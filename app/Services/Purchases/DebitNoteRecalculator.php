<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Models\PurchaseDebitNote;
use App\Models\Tax;
use App\Services\Inventory\StockedLineDefaults;
use App\Services\Sales\Data\LineAmounts;
use App\Services\Sales\InvoiceCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a debit note's figures.
 *
 * The correction document's rule, inherited from the sales credit note: the
 * rate is read from the LINE, never from the tax record. A note correcting a
 * bill keyed at 5% must hand back 5%, whatever the rate is today. Only a
 * line with no rate at all — a note against a bill from a predecessor
 * system, where there was nothing to copy — falls back to the chosen tax,
 * and then only once.
 */
final class DebitNoteRecalculator
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly StockedLineDefaults $stockedLines,
    ) {}

    public function recalculate(PurchaseDebitNote $note): PurchaseDebitNote
    {
        if (! $note->isDraft()) {
            return $note;
        }

        return DB::transaction(function () use ($note): PurchaseDebitNote {
            $items = $note->items()->get();
            $stocked = $this->stockedLines->stockedMap($items->pluck('product_id')->all());
            $inventoryAccount = in_array(true, $stocked, true)
                ? $this->stockedLines->inventoryAccountId()
                : null;

            /** @var list<LineAmounts> $resolved */
            $resolved = [];

            foreach ($items->values() as $index => $item) {
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

                $isStocked = $stocked[$item->product_id] ?? false;

                $item->forceFill([
                    'line_number' => $index + 1,
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

    private function currencyScale(PurchaseDebitNote $note): int
    {
        $currency = $note->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }
}
