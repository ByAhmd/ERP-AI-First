<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceSubtype;
use App\Enums\SystemAccount;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Sales\Exceptions\CreditNoteRejected;
use Illuminate\Support\Facades\DB;

/**
 * Approving a sales credit note.
 *
 * The invoice's posting with its sides exchanged, built from the credit note's
 * own totals:
 *
 *     DR  Sales revenue          the net taken back
 *     DR  VAT output payable     the tax no longer owed to ZATCA
 *     CR  Accounts receivable    what the customer no longer owes
 *
 * It posts its **own** entry rather than reversing the invoice's, which is the
 * decision this service turns on. Reversal looks tempting — the machinery
 * exists and pairs the two entries for free — and it is wrong four ways: it
 * carries no amount, so it cannot express a partial credit; the ledger's unique
 * index on `reverses_id` permits only one, so a second partial credit is a
 * constraint violation; it copies the original's source, so the entry would
 * claim the invoice as its document and the credit note would be invisible from
 * the ledger; and it numbers into the corrections series, which exists to keep
 * corrections out of the primary sequence. A credit note is a commercial event,
 * not a correction of a mistaken entry.
 *
 * Both zero-amount skips matter. A wholly zero-rated note owes no tax line, and
 * a rate-correction note — net nothing, tax something — is a legitimate
 * document under Article 40(1) that a literal mirror of the invoice's posting
 * would reject with a complaint about journal lines.
 */
final class CreditNotePoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly InvoiceOutstanding $outstanding,
    ) {}

    /**
     * Allocate a reference in the credit note's own series.
     *
     * The key is what separates the series, not the prefix: the allocator
     * applies its defaults only when it first creates the counter row, so
     * reusing the invoice's key with a different prefix would silently hand out
     * invoice numbers and leave permanent gaps in the invoice series.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'sales_credit_note',
            defaults: ['prefix' => 'CN-', 'padding' => 5],
        ));
    }

    public function approve(SalesCreditNote $note, ?string $userId = null): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $userId): SalesCreditNote {
            // Inside the transaction, and after locking the parent. Two
            // concurrent credit notes that each read "1,000 still creditable"
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
     * How much of an invoice is still open to be credited.
     *
     * Delegated to the shared {@see InvoiceOutstanding}, and the delegation is
     * the fix for a real bug: this used to be a two-term figure — total less
     * credit notes — which was correct until receipts existed and then
     * silently wasn't. A fully-paid invoice could be fully credited on top,
     * and the customer's receivable went negative inside a control account
     * that can never show it.
     */
    public function remainingOn(SalesInvoice $invoice): string
    {
        return $this->outstanding->outstanding($invoice);
    }

    /**
     * @return list<JournalLineData>
     */
    private function ledgerLines(SalesCreditNote $note): array
    {
        $net = $this->scale((string) $note->subtotal_net);
        $tax = $this->scale((string) $note->tax_total);
        $total = $this->scale((string) $note->total);

        $lines = [];

        if (bccomp($net, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::debit(
                $this->registry->get(SystemAccount::SalesRevenue)->getKey(),
                $net,
            );
        }

        if (bccomp($tax, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::debit(
                $this->registry->get(SystemAccount::VatOutputPayable)->getKey(),
                $tax,
            );
        }

        $lines[] = JournalLineData::credit(
            $this->registry->get(SystemAccount::AccountsReceivable)->getKey(),
            $total,
        );

        return $lines;
    }

    private function guard(SalesCreditNote $note): void
    {
        if ($note->isApproved()) {
            throw CreditNoteRejected::alreadyApproved($note);
        }

        if (! $note->isDraft()) {
            throw CreditNoteRejected::notDraft();
        }

        if ($note->items()->count() === 0) {
            throw CreditNoteRejected::noItems();
        }

        if (bccomp($this->scale((string) $note->total), '0', self::SCALE) === 0) {
            throw CreditNoteRejected::nothingToCredit($note);
        }

        $contact = $note->contact;

        if ($contact !== null && $contact->status !== ContactStatus::Active) {
            throw CreditNoteRejected::inactiveContact($contact);
        }

        if (! $note->totalsReconcile()) {
            throw CreditNoteRejected::totalsDoNotReconcile($note);
        }

        // Only for a note with no parent. Where a parent exists the subtype is
        // inherited from it, and a buyer who registered for VAT after
        // receiving a simplified invoice must still be able to have that
        // invoice credited — as simplified, referencing it.
        if ($note->parent_id === null
            && $note->subtype === InvoiceSubtype::Simplified
            && $contact !== null
            && $contact->isTaxRegistered()) {
            throw CreditNoteRejected::simplifiedForRegisteredBuyer($contact);
        }

        $this->guardAgainstParent($note);
    }

    /**
     * The checks that only apply when the invoice is one this platform holds.
     */
    private function guardAgainstParent(SalesCreditNote $note): void
    {
        if ($note->parent_id === null) {
            return;
        }

        // Locked, because the remaining balance is read and then acted on.
        $invoice = SalesInvoice::query()
            ->whereKey($note->parent_id)
            ->lockForUpdate()
            ->first();

        if ($invoice === null) {
            return;
        }

        // Whitelisted, never blacklisted. Testing for "not a draft" would let a
        // voided invoice through, and a voided invoice has already had its
        // entry reversed.
        if ($invoice->status !== DocumentStatus::Approved) {
            throw CreditNoteRejected::parentNotApproved($invoice);
        }

        if ($note->contact_id !== $invoice->contact_id) {
            // The ledger cannot catch this: receivable is one control account
            // with no contact on the line, so a credit against the wrong
            // customer balances perfectly and leaves both of them wrong.
            throw CreditNoteRejected::customerMismatch($note, $invoice);
        }

        if ($note->issue_date->lessThan($invoice->issue_date)) {
            // Crediting into a period before the supply was recognised would
            // restate a return that may already have been filed.
            throw CreditNoteRejected::datedBeforeInvoice($note, $invoice);
        }

        $remaining = $this->remainingOn($invoice);

        if (bccomp($this->scale((string) $note->total), $remaining, self::SCALE) > 0) {
            throw CreditNoteRejected::exceedsInvoice($note, $invoice, $remaining);
        }
    }

    private function narration(SalesCreditNote $note): string
    {
        return trim(__('sales.credit_notes.narration', [
            'reference' => $note->reference,
            'invoice' => $note->original_invoice_number,
        ]));
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
