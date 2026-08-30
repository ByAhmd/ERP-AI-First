<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\Tax;
use App\Services\Sales\Data\LineAmounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a quotation's figures from what was typed into it.
 *
 * The same arithmetic as the invoice, through the same {@see InvoiceCalculator}
 * — a quotation quotes exactly what the invoice would bill, or converting it
 * changes the price. A separate class rather than a shared one because the
 * models differ and a "quotation recalculator" must never grow a dependency on
 * SalesInvoice.
 *
 * Only drafts recalculate. An approved quotation is frozen: it is the offer
 * the customer holds, and a tax re-rate must not silently restate it. While
 * draft, lines track the current tax records exactly as draft invoices do.
 */
final class SalesQuotationRecalculator
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
    ) {}

    public function recalculate(SalesQuotation $quotation): SalesQuotation
    {
        if (! $quotation->isDraft()) {
            return $quotation;
        }

        return DB::transaction(function () use ($quotation): SalesQuotation {
            $items = $quotation->items()->get();
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

            $totals = $this->calculator->document($resolved, $this->currencyScale($quotation));

            $quotation->forceFill([
                'subtotal_net' => $totals->subtotalNet,
                'discount_total' => $totals->discountTotal,
                'tax_total' => $totals->taxTotal,
                'total' => $totals->total,
            ])->save();

            return $quotation->refresh();
        });
    }

    /**
     * The rates the lines refer to, read once.
     *
     * @param  Collection<int, SalesQuotationItem>  $items
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

    private function currencyScale(SalesQuotation $quotation): int
    {
        $currency = $quotation->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }
}
