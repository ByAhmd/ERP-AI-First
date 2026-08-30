<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tax;
use App\Services\Sales\Data\LineAmounts;
use App\Services\Sales\InvoiceCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an order's figures from what was typed into it.
 *
 * The same arithmetic as the bill, through the same calculator — an order
 * quotes exactly what the bill would carry, or converting it changes the
 * cost. Only drafts recalculate: an approved order is what was sent to the
 * supplier, and a tax re-rate must not silently restate it.
 */
final class PurchaseOrderRecalculator
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
    ) {}

    public function recalculate(PurchaseOrder $order): PurchaseOrder
    {
        if (! $order->isDraft()) {
            return $order;
        }

        return DB::transaction(function () use ($order): PurchaseOrder {
            $items = $order->items()->get();
            $taxes = $this->taxesFor($items);

            /** @var list<LineAmounts> $resolved */
            $resolved = [];

            foreach ($items->values() as $index => $item) {
                $tax = $item->tax_id === null ? null : ($taxes[$item->tax_id] ?? null);

                $amounts = $this->calculator->line(
                    quantity: (string) $item->quantity,
                    unitPrice: (string) $item->unit_price,
                    isInclusive: $item->is_inclusive,
                    discountType: $item->discount_type,
                    discountValue: (string) $item->discount_value,
                    taxRate: $tax === null ? '0' : (string) $tax->rate,
                    taxCategory: $tax?->category,
                );

                $item->forceFill([
                    'line_number' => $index + 1,
                    'tax_rate' => $amounts->taxRate,
                    'tax_category' => $amounts->taxCategory,
                    'discount_amount' => $amounts->discountAmount,
                    'net_amount' => $amounts->netAmount,
                    'tax_amount' => $amounts->taxAmount,
                    'line_total' => $amounts->lineTotal,
                ])->save();

                $resolved[] = $amounts;
            }

            $totals = $this->calculator->document($resolved, $this->currencyScale($order));

            $order->forceFill([
                'subtotal_net' => $totals->subtotalNet,
                'discount_total' => $totals->discountTotal,
                'tax_total' => $totals->taxTotal,
                'total' => $totals->total,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     * @return array<string, Tax>
     */
    private function taxesFor($items): array
    {
        $ids = $items->pluck('tax_id')->filter()->unique()->all();

        if ($ids === []) {
            return [];
        }

        return Tax::query()->whereKey($ids)->get()->keyBy(
            static fn (Tax $tax): string => $tax->getKey(),
        )->all();
    }

    private function currencyScale(PurchaseOrder $order): int
    {
        $currency = $order->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }
}
