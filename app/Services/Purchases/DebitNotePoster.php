<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseInvoice;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Purchases\Exceptions\DebitNoteRejected;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Approving a purchase debit note.
 *
 * The bill's posting with its sides exchanged:
 *
 *     DR  Accounts payable (2110)   what we no longer owe
 *     CR  each expense account      the net handed back, grouped per account
 *     CR  VAT input (1150)          the claim on ZATCA we no longer hold
 *
 * Its own entry, never a reversal — every reason the sales credit note lists
 * transfers: a reversal cannot express a partial return, the ledger permits
 * only one reversal per entry, the entry would claim the bill as its source,
 * and it would number into the corrections series.
 *
 * Both zero skips are load-bearing: a wholly zero-rated return has no tax
 * line, and a rate-correction note — net nothing, tax something — is a
 * legitimate correction a literal mirror would refuse.
 */
final class DebitNotePoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly BillOutstanding $outstanding,
    ) {}

    /**
     * Allocate a reference in the debit note's own series.
     *
     * DBN is Qoyod's own prefix. The key, not the prefix, is what separates
     * the series — reusing another key would silently hand out its numbers.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'purchase_debit_note',
            defaults: ['prefix' => 'DBN-', 'padding' => 5],
        ));
    }

    public function approve(PurchaseDebitNote $note, ?string $userId = null): PurchaseDebitNote
    {
        return DB::transaction(function () use ($note, $userId): PurchaseDebitNote {
            // Inside the transaction, after locking the parent — two
            // concurrent notes that each read the same remaining figure
            // outside a lock would both pass and both post.
            $this->guard($note);

            $entry = $this->poster->post(
                date: $note->issue_date,
                lines: $this->ledgerLines($note),
                description: $this->narration($note),
                reference: $note->reference,
                source: $note,
                userId: $userId,
            );

            $note->forceFill([
                'status' => DocumentStatus::Approved,
                'journal_entry_id' => $entry->getKey(),
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $note->refresh();
        });
    }

    /**
     * How much of a bill is still open to be corrected.
     */
    public function remainingOn(PurchaseInvoice $invoice): string
    {
        return $this->outstanding->outstanding($invoice);
    }

    /**
     * @return list<JournalLineData>
     */
    private function ledgerLines(PurchaseDebitNote $note): array
    {
        $tax = $this->scale((string) $note->tax_total);
        $total = $this->scale((string) $note->total);

        $lines = [
            JournalLineData::debit(
                $this->registry->get(SystemAccount::AccountsPayable)->getKey(),
                $total,
            ),
        ];

        foreach ($this->netByExpenseAccount($note) as $accountId => $net) {
            $lines[] = JournalLineData::credit($accountId, $net);
        }

        if (bccomp($tax, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::credit(
                $this->registry->get(SystemAccount::VatInputRecoverable)->getKey(),
                $tax,
            );
        }

        return $lines;
    }

    /**
     * Each expense account's share of the net handed back — the same
     * currency-scale projection the bill poster uses, so the group credits
     * sum to the stored subtotal identically.
     *
     * @return array<string, string>
     */
    private function netByExpenseAccount(PurchaseDebitNote $note): array
    {
        $scale = $this->currencyScale($note);

        /** @var array<string, BigRational> $groups */
        $groups = [];

        foreach ($note->items()->get() as $item) {
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

    private function guard(PurchaseDebitNote $note): void
    {
        if ($note->isApproved()) {
            throw DebitNoteRejected::alreadyApproved($note);
        }

        if (! $note->isDraft()) {
            throw DebitNoteRejected::notDraft();
        }

        if ($note->items()->count() === 0) {
            throw DebitNoteRejected::noItems();
        }

        if (bccomp($this->scale((string) $note->total), '0', self::SCALE) === 0) {
            throw DebitNoteRejected::nothingToDebit($note);
        }

        $contact = $note->contact;

        if ($contact !== null && $contact->status !== ContactStatus::Active) {
            throw DebitNoteRejected::inactiveSupplier($contact);
        }

        if (! $note->totalsReconcile()) {
            throw DebitNoteRejected::totalsDoNotReconcile($note);
        }

        $this->guardAgainstParent($note);
    }

    /**
     * The checks that only apply when the bill is one this platform holds.
     */
    private function guardAgainstParent(PurchaseDebitNote $note): void
    {
        if ($note->parent_id === null) {
            return;
        }

        // Locked, because the remaining balance is read and then acted on.
        $invoice = PurchaseInvoice::query()
            ->whereKey($note->parent_id)
            ->lockForUpdate()
            ->first();

        if ($invoice === null) {
            return;
        }

        // Whitelisted, never blacklisted — "not a draft" would let a voided
        // bill through, and a voided bill's entry is already reversed.
        if ($invoice->status !== DocumentStatus::Approved) {
            throw DebitNoteRejected::parentNotApproved($invoice);
        }

        if ($note->contact_id !== $invoice->contact_id) {
            // The ledger cannot catch this: payable is one control account
            // with no supplier on the line, so a note against the wrong
            // supplier balances perfectly and corrupts two balances.
            throw DebitNoteRejected::supplierMismatch($note, $invoice);
        }

        if ($note->issue_date->lessThan($invoice->issue_date)) {
            throw DebitNoteRejected::datedBeforeInvoice($note, $invoice);
        }

        $remaining = $this->remainingOn($invoice);

        if (bccomp($this->scale((string) $note->total), $remaining, self::SCALE) > 0) {
            throw DebitNoteRejected::exceedsInvoice($note, $invoice, $remaining);
        }
    }

    private function narration(PurchaseDebitNote $note): string
    {
        return trim(__('purchases.debit_notes.narration', [
            'reference' => $note->reference,
            'original' => $note->original_invoice_number,
        ]));
    }

    private function currencyScale(PurchaseDebitNote $note): int
    {
        $currency = $note->currency;

        return $currency === null ? 2 : $currency->decimal_places;
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
