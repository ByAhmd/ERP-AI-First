<?php

declare(strict_types=1);

namespace App\Services\Purchases\Exceptions;

use App\Models\Contact;
use App\Models\PurchaseOrder;
use RuntimeException;

/**
 * A purchase order that cannot be accepted, or a conversion that cannot
 * proceed.
 *
 * An order never touches the ledger, so none of these protect the books
 * directly — they protect the order's integrity and the one door through
 * which it becomes a bill that will post.
 */
final class PurchaseOrderRuleViolation extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('purchases.orders.errors.no_items'));
    }

    public static function alreadyApproved(PurchaseOrder $order): self
    {
        return new self(__('purchases.orders.errors.already_approved', [
            'reference' => $order->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('purchases.orders.errors.not_draft'));
    }

    public static function inactiveSupplier(Contact $contact): self
    {
        return new self(__('purchases.orders.errors.inactive_supplier', [
            'contact' => $contact->contact_name,
        ]));
    }

    public static function expiryBeforeIssue(): self
    {
        return new self(__('purchases.orders.errors.expiry_before_issue'));
    }

    public static function totalsDoNotReconcile(PurchaseOrder $order): self
    {
        return new self(__('purchases.orders.errors.totals_do_not_reconcile', [
            'reference' => $order->reference,
        ]));
    }

    public static function notApproved(PurchaseOrder $order): self
    {
        return new self(__('purchases.orders.errors.not_approved', [
            'reference' => $order->reference,
        ]));
    }

    public static function alreadyBilled(PurchaseOrder $order, ?string $invoiceReference): self
    {
        return new self(__('purchases.orders.errors.already_billed', [
            'reference' => $order->reference,
            'invoice' => $invoiceReference ?? '—',
        ]));
    }

    public static function taxNoLongerAvailable(string $taxName): self
    {
        return new self(__('purchases.orders.errors.tax_no_longer_available', [
            'tax' => $taxName,
        ]));
    }

    public static function cannotCancel(PurchaseOrder $order): self
    {
        return new self(__('purchases.orders.errors.cannot_cancel', [
            'reference' => $order->reference,
        ]));
    }
}
