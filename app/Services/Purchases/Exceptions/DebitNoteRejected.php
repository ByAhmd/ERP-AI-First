<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use App\Models\Contact;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseInvoice;
use RuntimeException;

/**
 * A purchase debit note that cannot be accepted.
 *
 * The control account carries no supplier, so every one of these failures
 * would balance perfectly in the ledger and be wrong in the books.
 */
final class DebitNoteRejected extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('purchases.debit_notes.errors.no_items'));
    }

    public static function alreadyApproved(PurchaseDebitNote $note): self
    {
        return new self(__('purchases.debit_notes.errors.already_approved', [
            'reference' => $note->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('purchases.debit_notes.errors.not_draft'));
    }

    public static function nothingToDebit(PurchaseDebitNote $note): self
    {
        return new self(__('purchases.debit_notes.errors.nothing_to_debit'));
    }

    public static function inactiveSupplier(Contact $contact): self
    {
        return new self(__('purchases.debit_notes.errors.inactive_supplier', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function parentNotApproved(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.debit_notes.errors.parent_not_approved'));
    }

    public static function supplierMismatch(PurchaseDebitNote $note, PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.debit_notes.errors.supplier_mismatch'));
    }

    public static function datedBeforeInvoice(PurchaseDebitNote $note, PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.debit_notes.errors.dated_before_parent'));
    }

    public static function exceedsInvoice(PurchaseDebitNote $note, PurchaseInvoice $invoice, string $remaining): self
    {
        return new self(__('purchases.debit_notes.errors.exceeds_remaining', [
            'amount' => (string) $note->total,
            'remaining' => $remaining,
        ]));
    }

    public static function totalsDoNotReconcile(PurchaseDebitNote $note): self
    {
        return new self(__('purchases.debit_notes.errors.totals_do_not_reconcile', [
            'reference' => $note->reference,
        ]));
    }
}
