<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceKind;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\PurchaseInvoice;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Purchases\Exceptions\PurchaseInvoiceRuleViolation;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Approving a purchase invoice.
 *
 * The mirror of the sales posting, with both the accounts and the sides
 * flipped — and the direction stated here because a half-flip balances
 * perfectly and lies:
 *
 *     DR  each line's expense account   the NET, grouped per account
 *     DR  VAT input recoverable (1150)  the tax — an ASSET, a claim on ZATCA
 *     CR  Accounts payable (2110)       the total the supplier is owed
 *
 * Input VAT is never a credit and never account 2120: debiting the expense
 * with the gross, or debiting 2120 instead of 1150, both balance — the first
 * overstates expense by the VAT and loses the claim, the second nets the VAT
 * position while corrupting both boxes of the return.
 *
 * The net is debited per distinct expense account, using the same
 * currency-scale projection the calculator uses to build subtotal_net, so
 * the group debits sum to the stored subtotal identically and the entry
 * balances by construction.
 */
final class PurchaseInvoicePoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    /**
     * Allocate a reference for a new standard bill.
     *
     * Its own series under its own key — never the sales key: the sales
     * series is ZATCA-gapless, and purchase documents drawing from it would
     * punch holes in it silently. BIL is Qoyod's own prefix. A gap in this
     * series, unlike the sales one, is not an incident: sequencing binds
     * documents we issue.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'purchase_invoice',
            defaults: ['prefix' => 'BIL-', 'padding' => 5],
        ));
    }

    /**
     * The simple bill's series — separate, as Qoyod numbers them.
     */
    public function nextSimpleReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'simple_purchase_invoice',
            defaults: ['prefix' => 'SB-', 'padding' => 5],
        ));
    }

    /**
     * Approve a draft: post it to the ledger.
     */
    public function approve(PurchaseInvoice $invoice, ?string $userId = null): PurchaseInvoice
    {
        $this->guard($invoice);

        return DB::transaction(function () use ($invoice, $userId): PurchaseInvoice {
            $entry = $this->poster->post(
                date: $invoice->issue_date,
                lines: $this->ledgerLines($invoice),
                description: $this->narration($invoice),
                reference: $invoice->reference,
                source: $invoice,
                userId: $userId,
            );

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
     * The ledger lines an approved bill produces.
     *
     * Built from stored figures, never recomputed — the totals are what was
     * keyed against the supplier's document.
     *
     * @return list<JournalLineData>
     */
    private function ledgerLines(PurchaseInvoice $invoice): array
    {
        $lines = [];

        foreach ($this->netByExpenseAccount($invoice) as $accountId => $net) {
            $lines[] = JournalLineData::debit($accountId, $net);
        }

        $tax = $this->scale((string) $invoice->tax_total);

        // Zero-rated and exempt bills owe no VAT and claim none; a zero line
        // would be refused by the poster anyway.
        if (bccomp($tax, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::debit(
                $this->registry->get(SystemAccount::VatInputRecoverable)->getKey(),
                $tax,
            );
        }

        $lines[] = JournalLineData::credit(
            $this->registry->get(SystemAccount::AccountsPayable)->getKey(),
            $this->scale((string) $invoice->total),
        );

        return $lines;
    }

    /**
     * Each expense account's share of the net, at the currency's minor unit.
     *
     * The projection must match the calculator's own — each line's net taken
     * to currency scale first, then summed — or the group debits drift from
     * the stored subtotal by a rounding hair and the entry refuses to
     * balance. Groups that sum to zero (a wholly discounted line) are
     * skipped; the ledger does not record nothing happening.
     *
     * @return array<string, string>
     */
    private function netByExpenseAccount(PurchaseInvoice $invoice): array
    {
        $scale = $this->currencyScale($invoice);

        /** @var array<string, BigRational> $groups */
        $groups = [];

        foreach ($invoice->items()->get() as $item) {
            $net = BigRational::of((string) $item->net_amount)
                ->toScale($scale, RoundingMode::HalfUp)
                ->toBigRational();

            $accountId = (string) $item->expense_account_id;

            $groups[$accountId] = ($groups[$accountId] ?? BigRational::zero())->plus($net);
        }

        $result = [];

        foreach ($groups as $accountId => $net) {
            $amount = (string) $net->toScale(self::SCALE, RoundingMode::HalfUp);

            if (bccomp($amount, '0', self::SCALE) !== 0) {
                $result[$accountId] = $amount;
            }
        }

        return $result;
    }

    private function guard(PurchaseInvoice $invoice): void
    {
        if ($invoice->isApproved()) {
            throw PurchaseInvoiceRuleViolation::alreadyApproved($invoice);
        }

        if (! $invoice->isDraft()) {
            throw PurchaseInvoiceRuleViolation::notDraft();
        }

        if ($invoice->items()->count() === 0) {
            throw PurchaseInvoiceRuleViolation::noItems();
        }

        $contact = $invoice->contact;

        // Deliberately stricter than the sales guard's null tolerance: a
        // cash sale is legitimate; an input-VAT claim with no supplier
        // identity behind it is what a tax invoice exists to prevent.
        if ($contact === null) {
            throw PurchaseInvoiceRuleViolation::missingSupplier();
        }

        if ($contact->type !== ContactType::Supplier) {
            throw PurchaseInvoiceRuleViolation::notASupplier($contact);
        }

        if ($contact->status !== ContactStatus::Active) {
            throw PurchaseInvoiceRuleViolation::inactiveSupplier($contact);
        }

        if ($invoice->kind === PurchaseInvoiceKind::Standard) {
            if ($invoice->due_date === null) {
                throw PurchaseInvoiceRuleViolation::dueDateRequired();
            }

            if ($invoice->due_date->lessThan($invoice->issue_date)) {
                throw PurchaseInvoiceRuleViolation::dueBeforeIssue();
            }
        }

        $this->guardExpenseAccounts($invoice);

        if (! $invoice->totalsReconcile()) {
            throw PurchaseInvoiceRuleViolation::totalsDoNotReconcile($invoice);
        }
    }

    /**
     * Every line must name a postable account before the ledger is asked.
     *
     * JournalPoster would refuse anyway, but its complaint would be about
     * ledger rules; the fault is on the bill's line.
     */
    private function guardExpenseAccounts(PurchaseInvoice $invoice): void
    {
        foreach ($invoice->items()->get() as $item) {
            if ($item->expense_account_id === null) {
                throw PurchaseInvoiceRuleViolation::expenseAccountMissing((int) $item->line_number);
            }

            $account = Account::query()->find($item->expense_account_id);

            if ($account === null) {
                throw PurchaseInvoiceRuleViolation::expenseAccountMissing((int) $item->line_number);
            }

            if (! $account->acceptsPostings()) {
                throw PurchaseInvoiceRuleViolation::expenseAccountNotPostable($account);
            }
        }
    }

    private function narration(PurchaseInvoice $invoice): string
    {
        $supplier = $invoice->contact?->displayName() ?? '';

        return trim(__('purchases.invoices.narration', [
            'reference' => $invoice->reference,
            'supplier' => $supplier,
        ]));
    }

    private function currencyScale(PurchaseInvoice $invoice): int
    {
        $currency = $invoice->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
