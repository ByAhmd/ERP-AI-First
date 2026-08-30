<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\ContactStatus;
use App\Enums\QuotationStatus;
use App\Models\SalesQuotation;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Sales\Exceptions\QuotationRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * The quotation's own lifecycle: numbering, approval, cancellation.
 *
 * Approval here is nothing like the invoice's. Qoyod's حفظ وموافقة on an
 * invoice posts to the ledger; on a quotation it only fixes the offer — the
 * status flips, a timestamp is written, and no account anywhere moves. The
 * ledger is reached exclusively through {@see QuotationConverter}, which
 * creates a separate invoice.
 */
final class SalesQuotationApprover
{
    public function __construct(
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    /**
     * Allocate a reference for a new quotation.
     *
     * Its own series under its own key. Quotations must never draw from the
     * `sales_invoice` counter: the invoice series is gapless for ZATCA, and
     * quotation-shaped holes in it would be ruinous and silent. QTE is
     * Qoyod's own prefix — its API sample data numbers quotations QTE1.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'sales_quotation',
            defaults: ['prefix' => 'QTE-', 'padding' => 5],
        ));
    }

    /**
     * Approve a draft: fix the offer. No ledger, no reports, nothing else.
     */
    public function approve(SalesQuotation $quotation, ?string $userId = null): SalesQuotation
    {
        $this->guard($quotation);

        return DB::transaction(function () use ($quotation, $userId): SalesQuotation {
            $quotation->forceFill([
                'status' => QuotationStatus::Approved,
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $quotation->refresh();
        });
    }

    /**
     * Cancel an open quotation — Qoyod's ملغي, and terminal.
     *
     * Only drafts and approved offers cancel. An invoiced quotation is frozen
     * provenance for its invoice; cancelling the offer after billing it would
     * be revisionism. Who cancelled and when is the audit log's record.
     */
    public function cancel(SalesQuotation $quotation): SalesQuotation
    {
        if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Approved], true)) {
            throw QuotationRuleViolation::cannotCancel($quotation);
        }

        return DB::transaction(function () use ($quotation): SalesQuotation {
            $quotation->forceFill(['status' => QuotationStatus::Cancelled])->save();

            return $quotation->refresh();
        });
    }

    /**
     * Everything that must hold before an offer is fixed.
     */
    private function guard(SalesQuotation $quotation): void
    {
        if ($quotation->status === QuotationStatus::Approved) {
            throw QuotationRuleViolation::alreadyApproved($quotation);
        }

        if (! $quotation->isDraft()) {
            throw QuotationRuleViolation::notDraft();
        }

        if (! $quotation->items()->exists()) {
            throw QuotationRuleViolation::noItems();
        }

        $contact = $quotation->contact;

        if ($contact === null || $contact->status !== ContactStatus::Active) {
            throw QuotationRuleViolation::inactiveContact($contact ?? $quotation->contact()->withTrashed()->firstOrFail());
        }

        if ($quotation->expiry_date->lessThan($quotation->issue_date)) {
            throw QuotationRuleViolation::expiryBeforeIssue();
        }

        if (! $quotation->totalsReconcile()) {
            throw QuotationRuleViolation::totalsDoNotReconcile($quotation);
        }
    }
}
