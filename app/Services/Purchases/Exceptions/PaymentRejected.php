<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use App\Models\Account;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use RuntimeException;

/**
 * A payment voucher that cannot be accepted.
 *
 * Every one of these produces a perfectly balanced entry if allowed
 * through: over-allocating balances, paying another supplier's bill
 * balances, paying out of the payable account itself balances as a wash.
 * There is no accounting backstop; each guard holds alone.
 */
final class PaymentRejected extends RuntimeException
{
    public static function alreadyApproved(SupplierPayment $payment): self
    {
        return new self(__('purchases.payments.errors.already_approved', [
            'reference' => $payment->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('purchases.payments.errors.not_draft'));
    }

    public static function nothingPaid(SupplierPayment $payment): self
    {
        return new self(__('purchases.payments.errors.zero_amount'));
    }

    public static function notASupplier(?Contact $contact): self
    {
        return $contact === null
            ? new self(__('purchases.payments.errors.missing_supplier'))
            : new self(__('purchases.payments.errors.not_a_supplier', [
                'contact' => $contact->contact_name,
            ]));
    }

    public static function inactiveSupplier(Contact $contact): self
    {
        return new self(__('purchases.payments.errors.inactive_supplier', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function paymentAccountInvalid(?Account $account): self
    {
        return new self(__('purchases.payments.errors.account_not_payment', [
            'account' => $account === null ? '—' : $account->code.' - '.$account->name,
        ]));
    }

    public static function allocationNotPositive(): self
    {
        return new self(__('purchases.payments.errors.zero_amount'));
    }

    public static function allocationsExceedAmount(SupplierPayment $payment): self
    {
        return new self(__('purchases.payments.errors.exceeds_unallocated', [
            'amount' => (string) $payment->allocatedTotal(),
            'remaining' => (string) $payment->amount,
        ]));
    }

    public static function invoiceNotFound(): self
    {
        return new self(__('purchases.payments.errors.invoice_not_approved'));
    }

    public static function invoiceNotApproved(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.payments.errors.invoice_not_approved'));
    }

    public static function supplierMismatch(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.payments.errors.invoice_wrong_supplier', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function currencyMismatch(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.payments.errors.currency_mismatch', [
            'invoice' => $invoice->reference,
        ]));
    }

    public static function datedBeforeInvoice(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.payments.errors.dated_before_invoice'));
    }

    public static function exceedsOutstanding(PurchaseInvoice $invoice, string $outstanding): self
    {
        return new self(__('purchases.payments.errors.exceeds_outstanding', [
            'amount' => $invoice->reference,
            'remaining' => $outstanding,
        ]));
    }

    public static function exceedsUnallocated(SupplierPayment $payment, string $unallocated): self
    {
        return new self(__('purchases.payments.errors.exceeds_unallocated', [
            'amount' => (string) $payment->amount,
            'remaining' => $unallocated,
        ]));
    }

    public static function invoiceAlreadyAllocated(PurchaseInvoice $invoice): self
    {
        return new self(__('purchases.payments.errors.allocation_exists'));
    }
}
