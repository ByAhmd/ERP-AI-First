<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Purchases\Exceptions\PaymentRejected;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier payment vouchers: approving them, and moving advances afterwards.
 *
 * The receipt poster with both the account roles AND the sides flipped —
 * and stated together, because flipping only one produces balanced garbage:
 *
 *     DR  Accounts payable (2110)        what was allocated to bills
 *     DR  Supplier advances (1170)       what was not — an ASSET, ours
 *     CR  payment account (user-chosen)  the money paid out
 *
 * Later allocation of an advance: DR payable / CR advances — the advance
 * asset is CREDITED, killed, the mirror image of the sales side where the
 * advance liability is debited. Unallocation mirrors back. Account 2180
 * never moves on any supplier document.
 *
 * Concurrency discipline inherited whole: the voucher row locked before
 * approval, every bill locked in id order, every balance read under lock.
 */
final class SupplierPaymentPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly BillOutstanding $outstanding,
    ) {}

    /**
     * Allocate a reference in the voucher's own series.
     *
     * PYT is Qoyod's own prefix for bill payments. Its own key — never
     * another document's, whose numbers it would silently consume.
     */
    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'supplier_payment',
            defaults: ['prefix' => 'PYT-', 'padding' => 5],
        ));
    }

    public function approve(SupplierPayment $payment, ?string $userId = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $userId): SupplierPayment {
            // Re-read under lock: two concurrent approvals must serialise.
            $locked = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardDocument($locked);

            $allocations = $locked->allocations()->get();
            $allocated = $this->guardAllocations($locked, $allocations);

            $entry = $this->poster->post(
                date: $locked->payment_date,
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
     * Apply part of an approved voucher's advance to a bill.
     *
     * Its own posting — DR payable, CR advances — dated the allocation, so
     * the money moves in the period it was applied rather than restating the
     * period it was paid.
     */
    public function allocate(
        SupplierPayment $payment,
        PurchaseInvoice $invoice,
        string $amount,
        DateTimeInterface $date,
        ?string $userId = null,
    ): SupplierPaymentAllocation {
        return DB::transaction(function () use ($payment, $invoice, $amount, $date, $userId): SupplierPaymentAllocation {
            $locked = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw PaymentRejected::notDraft();
            }

            $amount = $this->scale($amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw PaymentRejected::allocationNotPositive();
            }

            // Under the voucher lock: two concurrent allocations of the same
            // advance would otherwise both pass.
            $unallocated = $locked->unallocatedAmount();

            if (bccomp($amount, $unallocated, self::SCALE) > 0) {
                throw PaymentRejected::exceedsUnallocated($locked, $unallocated);
            }

            $lockedInvoice = PurchaseInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedInvoice === null) {
                throw PaymentRejected::invoiceNotFound();
            }

            if ($locked->allocations()->where('purchase_invoice_id', $lockedInvoice->getKey())->exists()) {
                // One row per bill per voucher; changing is unlink-then-relink.
                throw PaymentRejected::invoiceAlreadyAllocated($lockedInvoice);
            }

            $this->guardInvoice($locked, $lockedInvoice, $amount);

            $entry = $this->poster->post(
                date: $date,
                lines: [
                    // The advance asset is credited — killed — as the payable
                    // it settles is debited. Flipping either half alone
                    // balances and doubles the advance instead.
                    JournalLineData::debit(
                        $this->registry->get(SystemAccount::AccountsPayable)->getKey(),
                        $amount,
                    ),
                    JournalLineData::credit(
                        $this->registry->get(SystemAccount::SupplierAdvances)->getKey(),
                        $amount,
                    ),
                ],
                description: __('purchases.payments.allocation_narration', [
                    'reference' => $locked->reference,
                    'invoice' => $lockedInvoice->reference,
                ]),
                reference: $locked->reference,
                source: $locked,
                userId: $userId,
            );

            return SupplierPaymentAllocation::create([
                'supplier_payment_id' => $locked->getKey(),
                'purchase_invoice_id' => $lockedInvoice->getKey(),
                'amount' => $amount,
                'journal_entry_id' => $entry->getKey(),
            ]);
        });
    }

    /**
     * Return an allocation to the advance it came from.
     */
    public function unallocate(
        SupplierPaymentAllocation $allocation,
        DateTimeInterface $date,
        ?string $userId = null,
    ): void {
        DB::transaction(function () use ($allocation, $date, $userId): void {
            $payment = SupplierPayment::query()
                ->whereKey($allocation->supplier_payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $payment->isApproved()) {
                throw PaymentRejected::notDraft();
            }

            $amount = $this->scale((string) $allocation->amount);

            $this->poster->post(
                date: $date,
                lines: [
                    JournalLineData::debit(
                        $this->registry->get(SystemAccount::SupplierAdvances)->getKey(),
                        $amount,
                    ),
                    JournalLineData::credit(
                        $this->registry->get(SystemAccount::AccountsPayable)->getKey(),
                        $amount,
                    ),
                ],
                description: __('purchases.payments.unallocation_narration', [
                    'reference' => $payment->reference,
                    'invoice' => $allocation->invoice()->value('reference') ?? '',
                ]),
                reference: $payment->reference,
                source: $payment,
                userId: $userId,
            );

            $allocation->delete();
        });
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    private function guardDocument(SupplierPayment $payment): void
    {
        if ($payment->isApproved()) {
            throw PaymentRejected::alreadyApproved($payment);
        }

        if (! $payment->isDraft()) {
            throw PaymentRejected::notDraft();
        }

        if (bccomp($this->scale((string) $payment->amount), '0', self::SCALE) <= 0) {
            throw PaymentRejected::nothingPaid($payment);
        }

        $contact = $payment->contact;

        // Never delete this check to make a test pass — flip it. A voucher
        // paying a customer splits one party's balance across two control
        // accounts, and the ledger cannot see it.
        if ($contact === null || $contact->type !== ContactType::Supplier) {
            throw PaymentRejected::notASupplier($contact);
        }

        if ($contact->status !== ContactStatus::Active) {
            throw PaymentRejected::inactiveSupplier($contact);
        }

        $this->guardPaymentAccount($payment);
    }

    private function guardPaymentAccount(SupplierPayment $payment): void
    {
        /** @var ?Account $account */
        $account = $payment->paymentAccount;

        // The flag is the gate, not the account type: payable is itself on
        // the balance sheet, and paying out of it is a perfect wash.
        if ($account === null || ! $account->acceptsPostings() || ! $account->is_payment_account) {
            throw PaymentRejected::paymentAccountInvalid($account);
        }
    }

    /**
     * Validate every allocation under bill locks, and total them.
     *
     * @param  Collection<int, SupplierPaymentAllocation>  $allocations
     * @return string The allocated sum.
     */
    private function guardAllocations(SupplierPayment $payment, $allocations): string
    {
        $sum = '0.0000';

        foreach ($allocations as $allocation) {
            if (bccomp($this->scale((string) $allocation->amount), '0', self::SCALE) <= 0) {
                throw PaymentRejected::allocationNotPositive();
            }

            $sum = bcadd($sum, $this->scale((string) $allocation->amount), self::SCALE);
        }

        if (bccomp($sum, $this->scale((string) $payment->amount), self::SCALE) > 0) {
            throw PaymentRejected::allocationsExceedAmount($payment);
        }

        if ($allocations->isEmpty()) {
            return $sum;
        }

        $ids = $allocations->pluck('purchase_invoice_id')->unique()->values()->all();

        // Locked in id order, deterministically — two vouchers touching
        // bills {A,B} and {B,A} would deadlock on form order.
        $invoices = PurchaseInvoice::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (PurchaseInvoice $i): string => $i->getKey());

        if ($invoices->count() !== count($ids)) {
            throw PaymentRejected::invoiceNotFound();
        }

        foreach ($allocations as $allocation) {
            $this->guardInvoice(
                $payment,
                $invoices[$allocation->purchase_invoice_id],
                $this->scale((string) $allocation->amount),
            );
        }

        return $sum;
    }

    /**
     * The per-bill checks, shared by approval and later allocation.
     *
     * Caller must hold the bill lock.
     */
    private function guardInvoice(SupplierPayment $payment, PurchaseInvoice $invoice, string $amount): void
    {
        // Whitelisted, never blacklisted.
        if ($invoice->status !== DocumentStatus::Approved) {
            throw PaymentRejected::invoiceNotApproved($invoice);
        }

        // Payable is one control account with no supplier on the line —
        // paying another supplier's bill balances perfectly.
        if ($invoice->contact_id !== $payment->contact_id) {
            throw PaymentRejected::supplierMismatch($invoice);
        }

        // Kept verbatim from the sales side: refusing beats settling SAR
        // payable with USD. A USD supplier does not relax this.
        if ($payment->currency_id !== $invoice->currency_id) {
            throw PaymentRejected::currencyMismatch($invoice);
        }

        if ($payment->payment_date->lessThan($invoice->issue_date)) {
            // Money paid before the bill existed is not an error — it is an
            // advance. It approves unallocated and is applied later.
            throw PaymentRejected::datedBeforeInvoice($invoice);
        }

        $outstanding = $this->outstanding->outstanding($invoice);

        if (bccomp($amount, $outstanding, self::SCALE) > 0) {
            throw PaymentRejected::exceedsOutstanding($invoice, $outstanding);
        }
    }

    /**
     * @return list<JournalLineData>
     */
    private function approvalLines(SupplierPayment $payment, string $allocated): array
    {
        $total = $this->scale((string) $payment->amount);
        $unallocated = bcsub($total, $allocated, self::SCALE);

        $lines = [];

        // Both skips are mandatory: the poster refuses zero lines outright.
        if (bccomp($allocated, '0', self::SCALE) !== 0) {
            $lines[] = JournalLineData::debit(
                $this->registry->get(SystemAccount::AccountsPayable)->getKey(),
                $allocated,
            );
        }

        if (bccomp($unallocated, '0', self::SCALE) !== 0) {
            // The advance to the supplier — an asset debit on 1170, never a
            // movement on the customer side's 2180.
            $lines[] = JournalLineData::debit(
                $this->registry->get(SystemAccount::SupplierAdvances)->getKey(),
                $unallocated,
            );
        }

        $lines[] = JournalLineData::credit((string) $payment->payment_account_id, $total);

        return $lines;
    }

    private function narration(SupplierPayment $payment): string
    {
        return trim(__('purchases.payments.narration', [
            'reference' => $payment->reference,
            'supplier' => $payment->contact?->displayName() ?? '',
        ]));
    }

    private function scale(string $amount): string
    {
        return bcadd(trim($amount) === '' ? '0' : trim($amount), '0', self::SCALE);
    }
}
