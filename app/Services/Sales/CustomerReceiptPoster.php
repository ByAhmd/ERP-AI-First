<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Sales\Exceptions\ReceiptRejected;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer receipts: approving them, and moving advances afterwards.
 *
 * Approval posts one entry:
 *
 *     DR  deposit account (user-chosen)     the money received
 *     CR  Accounts receivable               what was allocated to invoices
 *     CR  Customer advances                 what was not
 *
 * with either credit omitted when zero — the poster refuses zero lines. The
 * unallocated remainder is a liability, not a receivable credit: crediting AR
 * for money no invoice explains would understate receivables against invoices
 * that still show outstanding, and the statement and the aging report would
 * disagree forever.
 *
 * Allocating an advance later is a **second accounting event**, not an edit:
 * its own entry — DR advances, CR receivable — at its own date, leaving the
 * receipt's original entry and its period untouched. Unallocating is the
 * mirror. That is why an allocation row can carry its own journal entry.
 *
 * Concurrency is the quiet danger everywhere here. Every guard that reads a
 * balance runs inside the transaction after locking what it read: the receipt
 * row itself (two concurrent approvals would double-post), and every invoice
 * being allocated, locked in id order so two receipts touching the same pair
 * of invoices cannot deadlock.
 */
final class CustomerReceiptPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly InvoiceOutstanding $outstanding,
    ) {}

    /**
     * Allocate a reference in the receipt's own series.
     *
     * Its own key, not its own prefix: the allocator applies defaults only
     * when it creates the counter row, so reusing another document's key would
     * consume that series' numbers and leave gaps in it.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'customer_receipt',
            defaults: ['prefix' => 'RCT-', 'padding' => 5],
        ));
    }

    public function approve(CustomerReceipt $receipt, ?string $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): CustomerReceipt {
            // Re-read under lock. Approving works on what is stored, and two
            // concurrent approvals of one receipt must serialise here.
            $locked = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardDocument($locked);

            $allocations = $locked->allocations()->get();
            $allocated = $this->guardAllocations($locked, $allocations);

            $entry = $this->poster->post(
                date: $locked->receipt_date,
                lines: $this->approvalLines($locked, $allocated),
                description: $this->narration($locked),
                reference: $locked->reference,
                source: $locked,
                userId: $userId,
            );

            $locked->forceFill([
                'status' => DocumentStatus::Approved,
                'journal_entry_id' => $entry->getKey(),
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Apply part of an approved receipt's advance to an invoice.
     *
     * Its own posting — DR advances, CR receivable — dated the allocation, so
     * the money moves in the period it was applied rather than restating the
     * period it arrived.
     */
    public function allocate(
        CustomerReceipt $receipt,
        SalesInvoice $invoice,
        string $amount,
        DateTimeInterface $date,
        ?string $userId = null,
    ): CustomerReceiptAllocation {
        return DB::transaction(function () use ($receipt, $invoice, $amount, $date, $userId): CustomerReceiptAllocation {
            $locked = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw ReceiptRejected::notDraft();
            }

            $amount = $this->scale($amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw ReceiptRejected::allocationNotPositive();
            }

            // Under the receipt lock: two concurrent allocations of the same
            // 400 advance would otherwise both pass.
            $unallocated = $locked->unallocatedAmount();

            if (bccomp($amount, $unallocated, self::SCALE) > 0) {
                throw ReceiptRejected::exceedsUnallocated($locked, $unallocated);
            }

            $lockedInvoice = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedInvoice === null) {
                throw ReceiptRejected::invoiceNotFound();
            }

            if ($locked->allocations()->where('sales_invoice_id', $lockedInvoice->getKey())->exists()) {
                // One row per invoice per receipt. Changing an allocation is
                // unlink-then-relink, which keeps every movement its own event.
                throw ReceiptRejected::invoiceAlreadyAllocated($lockedInvoice);
            }

            $this->guardInvoice($locked, $lockedInvoice, $amount);

            $entry = $this->poster->post(
                date: $date,
                lines: [
                    JournalLineData::debit(
                        $this->registry->get(SystemAccount::CustomerAdvances)->getKey(),
                        $amount,
                    ),
                    JournalLineData::credit(
                        $this->registry->get(SystemAccount::AccountsReceivable)->getKey(),
                        $amount,
                    ),
                ],
                description: __('sales.receipts.allocation_narration', [
                    'reference' => $locked->reference,
                    'invoice' => $lockedInvoice->reference,
                ]),
                reference: $locked->reference,
                source: $locked,
                userId: $userId,
            );

            return CustomerReceiptAllocation::create([
                'customer_receipt_id' => $locked->getKey(),
                'sales_invoice_id' => $lockedInvoice->getKey(),
                'amount' => $amount,
                'journal_entry_id' => $entry->getKey(),
            ]);
        });
    }

    /**
     * Return an allocation to the advance it came from.
     *
     * The mirror movement — DR receivable, CR advances — dated the release.
     * Nothing already posted is touched.
     */
    public function unallocate(
        CustomerReceiptAllocation $allocation,
        DateTimeInterface $date,
        ?string $userId = null,
    ): void {
        DB::transaction(function () use ($allocation, $date, $userId): void {
            $receipt = CustomerReceipt::query()
                ->whereKey($allocation->customer_receipt_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $receipt->isApproved()) {
                throw ReceiptRejected::notDraft();
            }

            $amount = $this->scale((string) $allocation->amount);

            $this->poster->post(
                date: $date,
                lines: [
                    JournalLineData::debit(
                        $this->registry->get(SystemAccount::AccountsReceivable)->getKey(),
                        $amount,
                    ),
                    JournalLineData::credit(
                        $this->registry->get(SystemAccount::CustomerAdvances)->getKey(),
                        $amount,
                    ),
                ],
                description: __('sales.receipts.unallocation_narration', [
                    'reference' => $receipt->reference,
                ]),
                reference: $receipt->reference,
                source: $receipt,
                userId: $userId,
            );

            $allocation->delete();
        });
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    private function guardDocument(CustomerReceipt $receipt): void
    {
        if ($receipt->isApproved()) {
            throw ReceiptRejected::alreadyApproved($receipt);
        }

        if (! $receipt->isDraft()) {
            throw ReceiptRejected::notDraft();
        }

        if (bccomp($this->scale((string) $receipt->amount), '0', self::SCALE) <= 0) {
            throw ReceiptRejected::nothingReceived($receipt);
        }

        $contact = $receipt->contact;

        if ($contact === null || $contact->type !== ContactType::Customer) {
            throw ReceiptRejected::notACustomer($contact);
        }

        if ($contact->status !== ContactStatus::Active) {
            throw ReceiptRejected::inactiveContact($contact);
        }

        $this->guardDepositAccount($receipt);
    }

    private function guardDepositAccount(CustomerReceipt $receipt): void
    {
        /** @var ?Account $account */
        $account = $receipt->depositAccount;

        // The flag is the gate, not the account type: receivable is itself an
        // asset, and a receipt deposited into it is a perfect wash entry.
        if ($account === null || ! $account->acceptsPostings() || ! $account->is_payment_account) {
            throw ReceiptRejected::depositAccountInvalid($account);
        }
    }

    /**
     * Validate every allocation under invoice locks, and total them.
     *
     * @param  Collection<int, CustomerReceiptAllocation>  $allocations
     * @return string The allocated sum.
     */
    private function guardAllocations(CustomerReceipt $receipt, $allocations): string
    {
        $sum = '0.0000';

        foreach ($allocations as $allocation) {
            if (bccomp($this->scale((string) $allocation->amount), '0', self::SCALE) <= 0) {
                throw ReceiptRejected::allocationNotPositive();
            }

            $sum = bcadd($sum, $this->scale((string) $allocation->amount), self::SCALE);
        }

        if (bccomp($sum, $this->scale((string) $receipt->amount), self::SCALE) > 0) {
            throw ReceiptRejected::allocationsExceedAmount($receipt);
        }

        if ($allocations->isEmpty()) {
            return $sum;
        }

        $ids = $allocations->pluck('sales_invoice_id')->unique()->values()->all();

        // Locked in id order, deterministically: two receipts touching
        // invoices {A,B} and {B,A} would deadlock on form order.
        $invoices = SalesInvoice::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (SalesInvoice $i): string => $i->getKey());

        if ($invoices->count() !== count($ids)) {
            throw ReceiptRejected::invoiceNotFound();
        }

        foreach ($allocations as $allocation) {
            $this->guardInvoice(
                $receipt,
                $invoices[$allocation->sales_invoice_id],
                $this->scale((string) $allocation->amount),
            );
        }

        return $sum;
    }

    /**
     * The per-invoice checks, shared by approval and later allocation.
     *
     * Caller must hold the invoice lock.
     */
    private function guardInvoice(CustomerReceipt $receipt, SalesInvoice $invoice, string $amount): void
    {
        // Whitelisted, never blacklisted: a voided invoice has already had its
        // entry reversed, and "not a draft" would let it through.
        if ($invoice->status !== DocumentStatus::Approved) {
            throw ReceiptRejected::invoiceNotApproved($invoice);
        }

        // The ledger can never catch this one: receivable is a single control
        // account with no contact on the line, so paying another customer's
        // invoice balances perfectly and corrupts both statements.
        if ($invoice->contact_id !== $receipt->contact_id) {
            throw ReceiptRejected::customerMismatch($invoice);
        }

        // No cross-currency arithmetic exists yet; refusing beats guessing.
        if ($receipt->currency_id !== $invoice->currency_id) {
            throw ReceiptRejected::currencyMismatch($invoice);
        }

        if ($receipt->receipt_date->lessThan($invoice->issue_date)) {
            // Money received before the invoice existed is not an error — it
            // is an advance. It approves unallocated and is applied later.
            throw ReceiptRejected::datedBeforeInvoice($invoice);
        }

        $outstanding = $this->outstanding->outstanding($invoice);

        if (bccomp($amount, $outstanding, self::SCALE) > 0) {
            throw ReceiptRejected::exceedsOutstanding($invoice, $outstanding);
        }
    }

    /**
     * @return list<JournalLineData>
     */
    private function approvalLines(CustomerReceipt $receipt, string $allocated): array
    {
        $total = $this->scale((string) $receipt->amount);
        $unallocated = bcsub($total, $allocated, self::SCALE);

        $lines = [
            JournalLineData::debit((string) $receipt->deposit_account_id, $total),
        ];

        // Both skips are mandatory: the poster refuses zero lines outright.
        if (bccomp($allocated, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::credit(
                $this->registry->get(SystemAccount::AccountsReceivable)->getKey(),
                $allocated,
            );
        }

        if (bccomp($unallocated, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::credit(
                $this->registry->get(SystemAccount::CustomerAdvances)->getKey(),
                $unallocated,
            );
        }

        return $lines;
    }

    private function narration(CustomerReceipt $receipt): string
    {
        return trim(__('sales.receipts.narration', [
            'reference' => $receipt->reference,
            'customer' => $receipt->contact?->displayName() ?? '',
        ]));
    }

    private function scale(string $amount): string
    {
        return bcadd(trim($amount) === '' ? '0' : trim($amount), '0', self::SCALE);
    }
}
