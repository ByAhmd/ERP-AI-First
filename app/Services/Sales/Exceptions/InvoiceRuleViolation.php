<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\Contact;
use App\Models\SalesInvoice;
use RuntimeException;

/**
 * An invoice that cannot be accepted.
 *
 * An invoice is both an accounting record and a document a customer holds, so
 * each of these would leave the two disagreeing.
 */
final class InvoiceRuleViolation extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('sales.invoices.errors.no_items'));
    }

    /**
     * Approved invoices have reached the ledger and the customer. Correction is
     * by credit note, which leaves both documents visible.
     */
    public static function alreadyApproved(SalesInvoice $invoice): self
    {
        return new self(__('sales.invoices.errors.already_approved', [
            'reference' => $invoice->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('sales.invoices.errors.not_draft'));
    }

    public static function inactiveContact(Contact $contact): self
    {
        return new self(__('sales.invoices.errors.inactive_contact', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function dueBeforeIssue(): self
    {
        return new self(__('sales.invoices.errors.due_before_issue'));
    }

    /**
     * A discount larger than the line it sits on would invert the supply.
     */
    public static function discountExceedsLine(): self
    {
        return new self(__('sales.invoices.errors.discount_exceeds_line'));
    }

    public static function negativeDiscount(): self
    {
        return new self(__('sales.invoices.errors.negative_discount'));
    }

    /**
     * The stored totals disagree with net plus tax.
     *
     * The poster would reject the unbalanced entry anyway, but its complaint
     * would be about debits and credits rather than about the invoice, which is
     * where the fault actually is.
     */
    public static function totalsDoNotReconcile(SalesInvoice $invoice): self
    {
        return new self(__('sales.invoices.errors.totals_do_not_reconcile', [
            'reference' => $invoice->reference,
        ]));
    }
}
