<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\Contact;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use RuntimeException;

/**
 * A credit note that cannot be accepted.
 *
 * Worth stating why these exist at all: a credit note reaches the ledger as a
 * perfectly balanced entry no matter how wrong it is. Over-crediting, crediting
 * the wrong customer, crediting at a rate that has since changed — every one of
 * them balances. There is no ledger backstop here, so each guard has to hold on
 * its own.
 */
final class CreditNoteRejected extends RuntimeException
{
    public static function alreadyApproved(SalesCreditNote $note): self
    {
        return new self(__('sales.credit_notes.errors.already_approved', [
            'reference' => $note->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('sales.credit_notes.errors.not_draft'));
    }

    public static function noItems(): self
    {
        return new self(__('sales.credit_notes.errors.no_items'));
    }

    public static function nothingToCredit(SalesCreditNote $note): self
    {
        return new self(__('sales.credit_notes.errors.nothing_to_credit', [
            'reference' => $note->reference,
        ]));
    }

    public static function inactiveContact(Contact $contact): self
    {
        return new self(__('sales.credit_notes.errors.inactive_contact', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function totalsDoNotReconcile(SalesCreditNote $note): self
    {
        return new self(__('sales.credit_notes.errors.totals_do_not_reconcile', [
            'reference' => $note->reference,
        ]));
    }

    /**
     * Only an approved invoice can be credited: a draft has never reached the
     * ledger, and a voided one has already had its entry reversed.
     */
    public static function parentNotApproved(SalesInvoice $invoice): self
    {
        return new self(__('sales.credit_notes.errors.parent_not_approved', [
            'reference' => $invoice->reference,
        ]));
    }

    public static function customerMismatch(SalesCreditNote $note, SalesInvoice $invoice): self
    {
        return new self(__('sales.credit_notes.errors.customer_mismatch', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function datedBeforeInvoice(SalesCreditNote $note, SalesInvoice $invoice): self
    {
        return new self(__('sales.credit_notes.errors.dated_before_invoice', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function exceedsInvoice(SalesCreditNote $note, SalesInvoice $invoice, string $remaining): self
    {
        return new self(__('sales.credit_notes.errors.exceeds_invoice', [
            'invoice' => $invoice->reference,
            'remaining' => number_format((float) $remaining, 2),
        ]));
    }
}
