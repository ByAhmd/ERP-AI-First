<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceSubtype;
use App\Enums\SystemAccount;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\Data\StockResult;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use App\Services\Inventory\StockLedger;
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
        private readonly StockLedger $stock,
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
            // Cost is the one figure resolved HERE, at approval, under the
            // stock lock — the opposite of every price snapshot. A draft
            // approved a week after it was written relieves at the average
            // of this moment, and the relief below IS the COGS figure.
            $stockResult = $this->issueStock($invoice);

            $lines = $this->ledgerLines($invoice);

            if ($stockResult !== null) {
                $relief = $stockResult->totalValue();

                // Zero-cost movements still moved quantity; the ledger only
                // hears about value — the zero-tax skip's pattern.
                if (bccomp($relief, '0', self::SCALE) !== 0) {
                    $lines[] = JournalLineData::debit(
                        $this->registry->get(SystemAccount::CostOfGoodsSold)->getKey(),
                        $relief,
                    );
                    $lines[] = JournalLineData::credit(
                        $this->registry->get(SystemAccount::Inventory)->getKey(),
                        $relief,
                    );
                }
            }

            $lines = array_map(
                fn (JournalLineData $line): JournalLineData => $line->withBranch($invoice->branch_id),
                $lines,
            );

            $entry = $this->poster->post(
                date: $invoice->issue_date,
                lines: $lines,
                description: $this->narration($invoice),
                reference: $invoice->reference,
                // The invoice is the source document, so the entry points back
                // at it and the audit trail runs both ways.
                source: $invoice,
                userId: $userId,
            );

            if ($stockResult !== null) {
                $this->stock->stampEntry($stockResult->movementIds(), $entry->getKey());
            }

            $invoice->forceFill([
                'status' => DocumentStatus::Approved,
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

    /**
     * Relieve the invoice's stocked lines from the stock ledger.
     *
     * Refuses at the branch that physically ships when quantity is short —
     * Qoyod's «الكمية غير متوفرة» — leaving the invoice a draft.
     */
    private function issueStock(SalesInvoice $invoice): ?StockResult
    {
        $stockedItems = $invoice->items()->get()->filter(
            fn ($item): bool => $item->is_stocked,
        );

        if ($stockedItems->isEmpty()) {
            return null;
        }

        $branch = $invoice->branch;

        if ($branch === null) {
            throw StockRuleViolation::branchRequired();
        }

        $lines = [];

        foreach ($stockedItems as $item) {
            $lines[] = new StockLine(
                productId: (string) $item->product_id,
                quantity: (string) $item->quantity,
            );
        }

        return $this->stock->issue(
            $invoice, $branch, $invoice->issue_date, $lines,
            $this->currencyScale($invoice),
        );
    }

    /**
     * The currency's minor unit — two for a riyal.
     */
    private function currencyScale(SalesInvoice $invoice): int
    {
        $currency = $invoice->currency;

        return $currency === null ? 2 : $currency->decimal_places;
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

        // A simplified invoice to a VAT-registered buyer is a compliance
        // failure, not a preference: it identifies no buyer, so the customer
        // has nothing to recover input VAT with.
        if ($invoice->subtype === InvoiceSubtype::Simplified
            && $contact !== null
            && $contact->isTaxRegistered()) {
            throw InvoiceRuleViolation::simplifiedForRegisteredBuyer($contact);
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
