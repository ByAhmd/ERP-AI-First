<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Purchases\Exceptions\PurchaseOrderRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * The purchase order's own lifecycle: numbering, approval, cancellation.
 *
 * Approval fixes the order and sends nothing to the ledger — Qoyod calls
 * the document إداري, and the books are reached exclusively through
 * {@see PurchaseOrderConverter}, which creates a separate bill.
 */
final class PurchaseOrderApprover
{
    public function __construct(
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    /**
     * Allocate a reference for a new order.
     *
     * ORD is Qoyod's own prefix. Its own key, its own series — never
     * another document's counter.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'purchase_order',
            defaults: ['prefix' => 'ORD-', 'padding' => 5],
        ));
    }

    /**
     * Approve a draft: fix the order. No ledger, no reports, nothing else.
     */
    public function approve(PurchaseOrder $order, ?string $userId = null): PurchaseOrder
    {
        $this->guard($order);

        return DB::transaction(function () use ($order, $userId): PurchaseOrder {
            $order->forceFill([
                'status' => PurchaseOrderStatus::Approved,
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * Cancel an open order — ملغي, and terminal.
     *
     * Only drafts and approved orders cancel. A billed order is frozen
     * provenance for its bill.
     */
    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved], true)) {
            throw PurchaseOrderRuleViolation::cannotCancel($order);
        }

        return DB::transaction(function () use ($order): PurchaseOrder {
            $order->forceFill(['status' => PurchaseOrderStatus::Cancelled])->save();

            return $order->refresh();
        });
    }

    private function guard(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrderStatus::Approved) {
            throw PurchaseOrderRuleViolation::alreadyApproved($order);
        }

        if (! $order->isDraft()) {
            throw PurchaseOrderRuleViolation::notDraft();
        }

        if (! $order->items()->exists()) {
            throw PurchaseOrderRuleViolation::noItems();
        }

        $contact = $order->contact;

        if ($contact === null
            || $contact->type !== ContactType::Supplier
            || $contact->status !== ContactStatus::Active) {
            throw PurchaseOrderRuleViolation::inactiveSupplier(
                $contact ?? $order->contact()->withTrashed()->firstOrFail(),
            );
        }

        if ($order->expiry_date->lessThan($order->issue_date)) {
            throw PurchaseOrderRuleViolation::expiryBeforeIssue();
        }

        if (! $order->totalsReconcile()) {
            throw PurchaseOrderRuleViolation::totalsDoNotReconcile($order);
        }
    }
}
