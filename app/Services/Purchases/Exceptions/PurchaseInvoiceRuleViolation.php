<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use App\Models\Account;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use RuntimeException;

/**
 * A purchase invoice that cannot be accepted.
 *
 * A bill claims input VAT and raises a debt in the supplier's name; each of
 * these refusals protects one of those claims from being wrong.
 */
final class PurchaseInvoiceRuleViolation extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('purchases.invoices.errors.no_items'));
    }

    public static function alreadyApproved(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.invoices.errors.already_approved', [
            'reference' => $invoice->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('purchases.invoices.errors.not_draft'));
    }

    /**
     * A bill with no supplier is an input-VAT claim with no identity behind
     * it — the thing a tax invoice exists to establish. The sales invoice
     * tolerates a null contact because a cash sale is legitimate; the mirror
     * deliberately does not.
     */
    public static function missingSupplier(): self
    {
        return new self(__('purchases.invoices.errors.missing_supplier'));
    }

    public static function notASupplier(Contact $contact): self
    {
        return new self(__('purchases.invoices.errors.not_a_supplier', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function inactiveSupplier(Contact $contact): self
    {
        return new self(__('purchases.invoices.errors.inactive_supplier', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function dueBeforeIssue(): self
    {
        return new self(__('purchases.invoices.errors.due_before_issue'));
    }

    public static function dueDateRequired(): self
    {
        return new self(__('purchases.invoices.errors.due_date_required'));
    }

    public static function expenseAccountMissing(int $lineNumber): self
    {
        return new self(__('purchases.invoices.errors.expense_account_missing', [
            'line' => $lineNumber,
        ]));
    }

    public static function expenseAccountNotPostable(Account $account): self
    {
        return new self(__('purchases.invoices.errors.expense_account_not_postable', [
            'account' => $account->code.' - '.$account->name,
        ]));
    }

    public static function totalsDoNotReconcile(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.invoices.errors.totals_do_not_reconcile', [
            'reference' => $invoice->reference,
        ]));
    }
}
