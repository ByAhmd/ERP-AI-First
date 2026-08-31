<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SalesCreditNote;
use App\Models\Tax;
use App\Services\Inventory\StockedLineDefaults;
use App\Services\Sales\Data\LineAmounts;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a credit note's figures.
 *
 * Deliberately not {@see SalesInvoiceRecalculator}, and the difference is one
 * line: that one reads the rate from the tax record, this one reads it from the
 * line. For a new invoice, resolving today's rate is right. For a document
 * correcting an invoice raised at 5% VAT before the rate changed, it is
 * catastrophic — it would return three times the tax ever collected, in a
 * perfectly balanced entry that no report would flag.
 *
 * The line's rate is copied from the invoice line when the credit note item is
 * created. Where a note credits an invoice this platform never held, the rate
 * is resolved from the chosen tax once and then left alone.
 */
final class CreditNoteRecalculator
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly StockedLineDefaults $stockedLines,
    ) {}

    public function recalculate(SalesCreditNote $note): SalesCreditNote
    {
        if (! $note->isDraft()) {
            return $note;
        }

        return DB::transaction(function () use ($note): SalesCreditNote {
            $items = $note->items()->get();
            $stocked = $this->stockedLines->stockedMap($items->pluck('product_id')->all());

            /** @var list<LineAmounts> $resolved */
            $resolved = [];

            foreach ($items->values() as $index => $item) {
                // The rate the line already carries. Only a line with no rate
                // at all — a credit note against an external invoice, where
                // there was nothing to copy from — falls back to the tax
                // record, and then only once.
                $rate = (string) $item->tax_rate;
                $category = $item->tax_category;

                if (bccomp($rate, '0', 4) === 0 && $item->tax_id !== null && $category === null) {
                    $tax = Tax::query()->withTrashed()->find($item->tax_id);

                    if ($tax !== null) {
                        $rate = (string) $tax->rate;
                        $category = $tax->category;
                    }
                }

                $amounts = $this->calculator->line(
                    quantity: (string) $item->quantity,
                    unitPrice: (string) $item->unit_price,
                    isInclusive: $item->is_inclusive,
                    discountType: $item->discount_type,
                    discountValue: (string) $item->discount_value,
                    taxRate: $rate,
                    taxCategory: $category,
                );

                $item->forceFill([
                    'line_number' => $index + 1,
                    'is_stocked' => $stocked[$item->product_id] ?? false,
                    'tax_rate' => $amounts->taxRate,
                    'tax_category' => $amounts->taxCategory,
                    'discount_amount' => $amounts->discountAmount,
                    'net_amount' => $amounts->netAmount,
                    'tax_amount' => $amounts->taxAmount,
                    'line_total' => $amounts->lineTotal,
                ])->save();

                $resolved[] = $amounts;
            }

            $totals = $this->calculator->document($resolved, $this->currencyScale($note));

            $note->forceFill([
                'subtotal_net' => $totals->subtotalNet,
                'discount_total' => $totals->discountTotal,
                'tax_total' => $totals->taxTotal,
                'total' => $totals->total,
            ])->save();

            return $note->refresh();
        });
    }

    private function currencyScale(SalesCreditNote $note): int
    {
        $currency = $note->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }
}
