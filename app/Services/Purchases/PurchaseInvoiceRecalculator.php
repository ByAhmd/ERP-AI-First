<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Tax;
use App\Services\Inventory\StockedLineDefaults;
use App\Services\Sales\Data\LineAmounts;
use App\Services\Sales\InvoiceCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a bill's figures from what was typed into it.
 *
 * The same InvoiceCalculator the sales side uses, deliberately: the
 * arithmetic of quantity, price, inclusive tax and discount does not care
 * which direction the document points. What differs is only which model the
 * results are written to.
 *
 * Drafts are recalculated freely. An approved bill is not: its figures have
 * reached the ledger, and correction is by debit note.
 */
final class PurchaseInvoiceRecalculator
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly StockedLineDefaults $stockedLines,
    ) {}

    public function recalculate(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if (! $invoice->isDraft()) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice): PurchaseInvoice {
            $items = $invoice->items()->get();
            $taxes = $this->taxesFor($items);
            $stocked = $this->stockedLines->stockedMap($items->pluck('product_id')->all());
            $inventoryAccount = in_array(true, $stocked, true)
                ? $this->stockedLines->inventoryAccountId()
                : null;

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

                $isStocked = $stocked[$item->product_id] ?? false;

                $item->forceFill([
                    'line_number' => $index + 1,
                    // The snapshot IS the redirect: a stocked line's account
                    // is written as المخزون here, so the document says what
                    // the ledger will do and the poster stays agnostic.
                    'is_stocked' => $isStocked,
                    'expense_account_id' => $isStocked ? $inventoryAccount : $item->expense_account_id,
                    'tax_rate' => $amounts->taxRate,
                    'tax_category' => $amounts->taxCategory,
                    'discount_amount' => $amounts->discountAmount,
                    'net_amount' => $amounts->netAmount,
                    'tax_amount' => $amounts->taxAmount,
                    'line_total' => $amounts->lineTotal,
                ])->save();

                $resolved[] = $amounts;
            }

            $totals = $this->calculator->document($resolved, $this->currencyScale($invoice));

            $invoice->forceFill([
                'subtotal_net' => $totals->subtotalNet,
                'discount_total' => $totals->discountTotal,
                'tax_total' => $totals->taxTotal,
                'total' => $totals->total,
            ])->save();

            return $invoice->refresh();
        });
    }

    /**
     * The rates the lines refer to, read once.
     *
     * @param  Collection<int, PurchaseInvoiceItem>  $items
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

    private function currencyScale(PurchaseInvoice $invoice): int
    {
        $currency = $invoice->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }
}
