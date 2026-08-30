<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\Contact;
use App\Models\SalesQuotation;
use RuntimeException;

/**
 * A quotation that cannot be accepted, or a conversion that cannot proceed.
 *
 * A quotation never touches the ledger, so none of these protect the books
 * directly. What they protect is the offer's integrity — an approved quotation
 * is a document the customer holds — and the conversion path, which is the one
 * door through which a quotation becomes something that will post.
 */
final class QuotationRuleViolation extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('sales.quotations.errors.no_items'));
    }

    public static function alreadyApproved(SalesQuotation $quotation): self
    {
        return new self(__('sales.quotations.errors.already_approved', [
            'reference' => $quotation->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('sales.quotations.errors.not_draft'));
    }

    public static function inactiveContact(Contact $contact): self
    {
        return new self(__('sales.quotations.errors.inactive_contact', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function expiryBeforeIssue(): self
    {
        return new self(__('sales.quotations.errors.expiry_before_issue'));
    }

    public static function totalsDoNotReconcile(SalesQuotation $quotation): self
    {
        return new self(__('sales.quotations.errors.totals_do_not_reconcile', [
            'reference' => $quotation->reference,
        ]));
    }

    /**
     * Only an approved quotation converts. A draft was never offered, a
     * cancelled one was withdrawn.
     */
    public static function notApproved(SalesQuotation $quotation): self
    {
        return new self(__('sales.quotations.errors.not_approved', [
            'reference' => $quotation->reference,
        ]));
    }

    /**
     * One quotation, one invoice — Qoyod has no partial invoicing. The unique
     * index below the service enforces the same rule against a race.
     */
    public static function alreadyInvoiced(SalesQuotation $quotation, ?string $invoiceReference): self
    {
        return new self(__('sales.quotations.errors.already_invoiced', [
            'reference' => $quotation->reference,
            'invoice' => $invoiceReference ?? '—',
        ]));
    }

    /**
     * A quoted tax has since been deleted. Refused loudly, because the
     * recalculator's fallback for an unresolvable tax is a rate of zero — and
     * a silently zero-rated invoice is exactly the class of bug this platform
     * exists to kill.
     */
    public static function taxNoLongerAvailable(string $taxName): self
    {
        return new self(__('sales.quotations.errors.tax_no_longer_available', [
            'tax' => $taxName,
        ]));
    }

    public static function cannotCancel(SalesQuotation $quotation): self
    {
        return new self(__('sales.quotations.errors.cannot_cancel', [
            'reference' => $quotation->reference,
        ]));
    }
}
