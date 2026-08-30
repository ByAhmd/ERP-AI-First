<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceSubtype;
use App\Enums\QuotationStatus;
use App\Models\SalesInvoice;
use App\Models\SalesQuotation;
use App\Models\Tax;
use App\Services\Sales\Exceptions\QuotationRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Turning an approved quotation into a draft invoice — تحويل لفاتورة.
 *
 * The one door from the commercial document into the accounting one, built
 * around a single split, stated here because it is the whole design:
 *
 * Carried verbatim — the commercial agreement: the customer, the products,
 * the quantities, the quoted prices, the discounts. The price is what was
 * quoted; re-resolving it from the product would silently renege on the offer.
 *
 * Re-resolved at conversion — the fiscal facts: the invoice's own reference
 * from its own gapless series, its subtype from the customer's current VAT
 * registration, its dates from today, and every tax rate from the current tax
 * record. A quotation priced in March converts in June at June's law. The
 * converter therefore never copies a derived figure — it carries raw inputs
 * and leaves the arithmetic to {@see SalesInvoiceRecalculator}, because copied
 * March arithmetic passes every reconciliation check; it was right in March.
 *
 * One quotation, one invoice. The unique index on sales_invoices.quotation_id
 * decides the race below this service; the row lock decides it inside.
 */
final class QuotationConverter
{
    public function __construct(
        private readonly SalesInvoicePoster $invoices,
        private readonly SalesInvoiceRecalculator $recalculator,
    ) {}

    /**
     * Convert, returning the new draft invoice for the clerk to review.
     *
     * The draft is created and the quotation flipped in one transaction —
     * unlike Qoyod, which opens an unsaved pre-filled form and flips on save.
     * The deviation buys race-safety; the abandonment path is the deleting
     * hook on SalesInvoice, which reverts the quotation to Approved if the
     * still-draft invoice is discarded.
     */
    public function convert(SalesQuotation $quotation, ?string $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($quotation, $userId): SalesInvoice {
            /** @var SalesQuotation $locked */
            $locked = SalesQuotation::query()
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($locked);

            $contact = $locked->contact()->firstOrFail();

            $invoice = SalesInvoice::query()->create([
                'company_id' => $locked->company_id,
                // Drawn from the invoice series now, never derived from the
                // quotation's number — the two series must not touch.
                'reference' => $this->invoices->nextReference(),
                'status' => DocumentStatus::Draft,
                // The customer may have VAT-registered since quoting; the
                // document class follows their registration today.
                'subtype' => InvoiceSubtype::forContact($contact),
                'contact_id' => $locked->contact_id,
                'quotation_id' => $locked->getKey(),
                'issue_date' => today(),
                'due_date' => today(),
                'supply_date' => today(),
                'description' => $locked->description,
                'terms_and_conditions' => $locked->terms_and_conditions,
                'notes' => $locked->notes,
                'currency_id' => $locked->currency_id,
                'created_by_id' => $userId,
            ]);

            foreach ($locked->items()->get() as $line) {
                // Raw inputs only. Every derived column — rate, category,
                // discount amount, net, tax, total — stays at its default for
                // the recalculator to resolve at today's law.
                $invoice->items()->create([
                    'company_id' => $locked->company_id,
                    'line_number' => $line->line_number,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product_name,
                    'product_description' => $line->product_description,
                    'unit_name' => $line->unit_name,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'is_inclusive' => $line->is_inclusive,
                    'discount_value' => (string) $line->discount_value,
                    'discount_type' => $line->discount_type,
                    'tax_id' => $line->tax_id,
                ]);
            }

            $invoice = $this->recalculator->recalculate($invoice);

            $locked->forceFill(['status' => QuotationStatus::Invoiced])->save();

            return $invoice;
        });
    }

    /**
     * Everything that must hold before the offer becomes a bill.
     *
     * Expiry deliberately does not block: Qoyod converts any approved
     * quotation, and the real mitigation is structural — conversion lands in
     * an editable draft reviewed by a human before the poster's own guards
     * run. The UI warns when the offer has lapsed.
     */
    private function guard(SalesQuotation $quotation): void
    {
        if ($quotation->status === QuotationStatus::Invoiced) {
            throw QuotationRuleViolation::alreadyInvoiced(
                $quotation,
                $quotation->invoice()->value('reference'),
            );
        }

        if (! $quotation->isApproved()) {
            throw QuotationRuleViolation::notApproved($quotation);
        }

        $contact = $quotation->contact;

        if ($contact === null || $contact->status !== ContactStatus::Active) {
            throw QuotationRuleViolation::inactiveContact(
                $contact ?? $quotation->contact()->withTrashed()->firstOrFail(),
            );
        }

        $this->guardTaxesResolve($quotation);
    }

    /**
     * Every quoted tax must still resolve.
     *
     * Taxes soft-delete, and the recalculator's fallback for a tax it cannot
     * find is a rate of zero. Without this check, converting a quotation whose
     * tax was deleted would produce a silently zero-rated invoice — no error,
     * wrong VAT. The refusal names the tax so the fix is obvious.
     */
    private function guardTaxesResolve(SalesQuotation $quotation): void
    {
        $ids = $quotation->items()->pluck('tax_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $alive = Tax::query()->whereKey($ids->all())->pluck('id');
        $missing = $ids->diff($alive);

        if ($missing->isEmpty()) {
            return;
        }

        $trashed = Tax::query()->withTrashed()->find($missing->first());
        $name = $trashed === null ? (string) $missing->first() : $trashed->name;

        throw QuotationRuleViolation::taxNoLongerAvailable($name);
    }
}
