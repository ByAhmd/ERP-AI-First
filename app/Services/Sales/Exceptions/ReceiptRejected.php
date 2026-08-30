<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\SalesInvoice;
use RuntimeException;

/**
 * A receipt that cannot be accepted.
 *
 * The same discipline as the credit note's: every one of these produces a
 * perfectly balanced ledger entry if allowed through. Over-allocating
 * balances. Depositing into the receivable account itself balances — a wash
 * in which money vanishes from no account. Paying another customer's invoice
 * balances. There is no accounting backstop; each guard holds alone.
 */
final class ReceiptRejected extends RuntimeException
{
    public static function alreadyApproved(CustomerReceipt $receipt): self
    {
        return new self(__('sales.receipts.errors.already_approved', [
            'reference' => $receipt->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('sales.receipts.errors.not_draft'));
    }

    public static function nothingReceived(CustomerReceipt $receipt): self
    {
        return new self(__('sales.receipts.errors.nothing_received', [
            'reference' => $receipt->reference,
        ]));
    }

    public static function inactiveContact(Contact $contact): self
    {
        return new self(__('sales.receipts.errors.inactive_contact', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function notACustomer(?Contact $contact): self
    {
        return new self(__('sales.receipts.errors.not_a_customer', [
            'contact' => $contact->contact_name ?? '—',
        ]));
    }

    /**
     * The deposit account is missing, closed to postings, or not flagged as a
     * payment account. The flag is the gate — receivable is itself an asset,
     * so a type check would wave through the exact wash entry this exists to
     * stop.
     */
    public static function depositAccountInvalid(?Account $account): self
    {
        return new self(__('sales.receipts.errors.deposit_account_invalid', [
            'account' => $account === null ? '—' : $account->code.' '.$account->name,
        ]));
    }

    public static function allocationNotPositive(): self
    {
        return new self(__('sales.receipts.errors.allocation_not_positive'));
    }

    public static function allocationsExceedAmount(CustomerReceipt $receipt): self
    {
        return new self(__('sales.receipts.errors.allocations_exceed_amount', [
            'reference' => $receipt->reference,
        ]));
    }

    public static function invoiceNotFound(): self
    {
        return new self(__('sales.receipts.errors.invoice_not_found'));
    }

    public static function invoiceNotApproved(SalesInvoice $invoice): self
    {
        return new self(__('sales.receipts.errors.invoice_not_approved', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function customerMismatch(SalesInvoice $invoice): self
    {
        return new self(__('sales.receipts.errors.customer_mismatch', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function currencyMismatch(SalesInvoice $invoice): self
    {
        return new self(__('sales.receipts.errors.currency_mismatch', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function datedBeforeInvoice(SalesInvoice $invoice): self
    {
        return new self(__('sales.receipts.errors.dated_before_invoice', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function exceedsOutstanding(SalesInvoice $invoice, string $outstanding): self
    {
        return new self(__('sales.receipts.errors.exceeds_outstanding', [
            'invoice' => $invoice->reference,
            'outstanding' => number_format((float) $outstanding, 2),
        ]));
    }

    public static function invoiceAlreadyAllocated(SalesInvoice $invoice): self
    {
        return new self(__('sales.receipts.errors.invoice_already_allocated', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function exceedsUnallocated(CustomerReceipt $receipt, string $unallocated): self
    {
        return new self(__('sales.receipts.errors.exceeds_unallocated', [
            'reference' => $receipt->reference,
            'unallocated' => number_format((float) $unallocated, 2),
        ]));
    }
}
