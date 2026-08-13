<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\ContactStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SystemAccount;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Sales\Exceptions\InvoiceRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Approving a sales invoice.
 *
 * Qoyod draws the line in its own help text: `حفظ كمسودة` stores an invoice
 * that affects neither the accounts nor the reports, and `حفظ وموافقة` makes it
 * final. This is the second of those, and it is the only path by which a sales
 * invoice reaches the ledger.
 *
 * The posting itself is three lines and is the whole reason this platform was
 * rebuilt:
 *
 *     DR  Accounts receivable   the total the customer owes
 *     CR  Sales revenue         the NET, tax excluded
 *     CR  VAT output payable    the tax
 *
 * The predecessor system credited revenue with the tax-inclusive total and
 * wrote no tax line at all, so revenue was overstated by the VAT and the return
 * could never reconcile to the trial balance. Revenue is credited with the net
 * here even when the line was priced tax-inclusive, which is the case that hid
 * the original bug.
 *
 * Everything reaches the ledger through {@see JournalPoster}, so an invoice
 * inherits the gapless numbering, the open-period check and the balance
 * assertion rather than reimplementing any of them.
 */
final class SalesInvoicePoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    /**
     * Allocate a reference for a new invoice.
     *
     * Drafts are numbered, unlike draft journal entries: the reference is how a
     * clerk refers to an invoice they are still working on, and Qoyod shows one
     * from the moment the form opens.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'sales_invoice',
            defaults: ['prefix' => 'INV-', 'padding' => 5],
        ));
    }

    /**
     * Approve a draft: write its totals and post it to the ledger.
     */
    public function approve(SalesInvoice $invoice, ?string $userId = null): SalesInvoice
    {
        $this->guard($invoice);

        return DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $entry = $this->poster->post(
                date: $invoice->issue_date,
                lines: $this->ledgerLines($invoice),
                description: $this->narration($invoice),
                reference: $invoice->reference,
                // The invoice is the source document, so the entry points back
                // at it and the audit trail runs both ways.
                source: $invoice,
                userId: $userId,
            );

            $invoice->forceFill([
                'status' => InvoiceStatus::Approved,
                'journal_entry_id' => $entry->getKey(),
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $invoice->refresh();
        });
    }

    /**
     * The ledger lines an approved invoice produces.
     *
     * Built from the invoice's stored totals rather than recomputed from its
     * lines. The totals are what the customer was billed; a ledger that
     * disagreed with the document in the customer's hand would be the wrong one
     * even if its arithmetic were better.
     *
     * @return list<JournalLineData>
     */
    private function ledgerLines(SalesInvoice $invoice): array
    {
        $receivable = $this->registry->get(SystemAccount::AccountsReceivable);
        $revenue = $this->registry->get(SystemAccount::SalesRevenue);

        $net = $this->scale((string) $invoice->subtotal_net);
        $tax = $this->scale((string) $invoice->tax_total);
        $total = $this->scale((string) $invoice->total);

        $lines = [
            JournalLineData::debit($receivable->getKey(), $total),
            JournalLineData::credit($revenue->getKey(), $net),
        ];

        // A wholly zero-rated or exempt invoice owes nothing, and a zero line
        // would be noise in the ledger — and is refused outright by the poster.
        if (bccomp($tax, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::credit(
                $this->registry->get(SystemAccount::VatOutputPayable)->getKey(),
                $tax,
            );
        }

        return $lines;
    }

    private function guard(SalesInvoice $invoice): void
    {
        if ($invoice->isApproved()) {
            throw InvoiceRuleViolation::alreadyApproved($invoice);
        }

        if (! $invoice->isDraft()) {
            throw InvoiceRuleViolation::notDraft();
        }

        if ($invoice->items()->count() === 0) {
            throw InvoiceRuleViolation::noItems();
        }

        $contact = $invoice->contact;

        if ($contact !== null && $contact->status !== ContactStatus::Active) {
            throw InvoiceRuleViolation::inactiveContact($contact);
        }

        if ($invoice->due_date->lessThan($invoice->issue_date)) {
            throw InvoiceRuleViolation::dueBeforeIssue();
        }

        // Belt and braces against a totals row that was written by something
        // other than the calculator. JournalPoster would reject the unbalanced
        // entry anyway, but its message would be about debits and credits
        // rather than about the invoice.
        if (! $invoice->totalsReconcile()) {
            throw InvoiceRuleViolation::totalsDoNotReconcile($invoice);
        }
    }

    private function narration(SalesInvoice $invoice): string
    {
        $customer = $invoice->contact?->displayName() ?? '';

        return trim(__('sales.invoices.narration', [
            'reference' => $invoice->reference,
            'customer' => $customer,
        ]));
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
