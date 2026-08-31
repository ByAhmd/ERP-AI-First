<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Services\Inventory\StockedLineDefaults;
use App\Services\Sales\Data\LineAmounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an invoice's figures from what was typed into it.
 *
 * A form supplies raw inputs — a quantity, a price, whether that price includes
 * tax, a discount and a chosen rate. Everything else on the line and every
 * total on the header is derived, and derived here, so a line saved through the
 * screen and a line built by an import end up with identical figures.
 *
 * The rate and category are snapshotted onto the line as they are resolved. The
 * line keeps pointing at its tax for provenance, but nothing reads back through
 * that pointer to produce a number: re-rating VAT must not restate invoices
 * already issued.
 *
 * Drafts are recalculated freely. An approved invoice is not: its figures have
 * reached the ledger and the customer, and correction is by credit note.
 */
final class SalesInvoiceRecalculator
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly StockedLineDefaults $stockedLines,
    ) {}

    public function recalculate(SalesInvoice $invoice): SalesInvoice
    {
        if (! $invoice->isDraft()) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice): SalesInvoice {
            $items = $invoice->items()->get();
            $taxes = $this->taxesFor($items);
            $stocked = $this->stockedLines->stockedMap($items->pluck('product_id')->all());

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
                    // Contiguous from one, whatever order the form supplied.
                    'line_number' => $index + 1,
                    // The stock snapshot: what this line will do at approval
                    // is decided here, not by the product's state then.
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
     * @param  Collection<int, SalesInvoiceItem>  $items
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

    /**
     * The minor unit the document's tax groups are totalled at.
     *
     * Two for a riyal. It comes from the currency rather than a constant
     * because the grouping rule is scale-sensitive, and a currency with three
     * decimals would round differently.
     */
    private function currencyScale(SalesInvoice $invoice): int
    {
        $currency = $invoice->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }
}
