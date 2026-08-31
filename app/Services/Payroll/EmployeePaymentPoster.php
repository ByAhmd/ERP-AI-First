<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\EmployeeBonus;
use App\Models\EmployeePaymentVoucher;
use App\Models\Payslip;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Payroll\Exceptions\VoucherRejected;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Employee payment vouchers — Qoyod's سند موظف, paying the payable down.
 *
 * DR رواتب مستحقة / CR the payment account, and every riyal must land on
 * a named payslip or bonus: a voucher is FULLY allocated or refused.
 * There is no unallocated residue path through a voucher — advances are
 * the only prepayment vehicle — which is what keeps 2140 reconcilable to
 * its subledger.
 */
final class EmployeePaymentPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly PayrollOutstanding $outstanding,
    ) {}

    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'employee_payment',
            defaults: ['prefix' => 'EPV-', 'padding' => 5],
        ));
    }

    public function approve(EmployeePaymentVoucher $voucher, ?string $userId = null): EmployeePaymentVoucher
    {
        return DB::transaction(function () use ($voucher, $userId): EmployeePaymentVoucher {
            $locked = EmployeePaymentVoucher::query()
                ->whereKey($voucher->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isApproved()) {
                throw VoucherRejected::alreadyApproved($locked->reference);
            }

            if (! $locked->isDraft()) {
                throw VoucherRejected::notDraft();
            }

            $amount = $this->scale((string) $locked->amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw VoucherRejected::amountNotPositive();
            }

            $account = $locked->paymentAccount()->first();

            if ($account === null || ! $account->acceptsPostings() || ! $account->is_payment_account) {
                throw \App\Services\Payroll\Exceptions\PayrollRuleViolation::paymentAccountInvalid($account);
            }

            $allocated = $this->guardAllocations($locked);

            // Every riyal named, or nothing moves.
            if (bccomp($allocated, $amount, self::SCALE) !== 0) {
                throw VoucherRejected::notFullyAllocated();
            }

            $employee = $locked->employee()->firstOrFail();

            $entry = $this->poster->post(
                date: $locked->payment_date,
                lines: array_map(
                    fn (JournalLineData $line): JournalLineData => $line->withBranch($employee->branch_id),
                    [
                        JournalLineData::debit(
                            $this->registry->get(SystemAccount::SalariesPayable)->getKey(),
                            $amount,
                        ),
                        JournalLineData::credit($account->getKey(), $amount),
                    ],
                ),
                description: trim(__('payroll.vouchers.narration', [
                    'reference' => $locked->reference,
                    'employee' => $employee->fullName(),
                ])),
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
     * Undo an approved voucher: the entry reverses and the allocations
     * drop together, restoring every target's outstanding.
     */
    public function reverse(EmployeePaymentVoucher $voucher, CarbonImmutable $date, ?string $userId = null): EmployeePaymentVoucher
    {
        return DB::transaction(function () use ($voucher, $date, $userId): EmployeePaymentVoucher {
            $locked = EmployeePaymentVoucher::query()
                ->whereKey($voucher->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw VoucherRejected::notDraft();
            }

            $reversal = $this->poster->reverse(
                original: $locked->journalEntry()->firstOrFail(),
                date: $date,
                userId: $userId,
            );

            $locked->allocations()->delete();

            $locked->forceFill([
                'status' => DocumentStatus::Void,
                'reversal_journal_entry_id' => $reversal->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Validate every allocation under target locks, and total them.
     */
    private function guardAllocations(EmployeePaymentVoucher $voucher): string
    {
        $allocations = $voucher->allocations()->get();

        if ($allocations->isEmpty()) {
            throw VoucherRejected::notFullyAllocated();
        }

        // Locked in id order per target type — deterministic, deadlock-free.
        $payslipIds = $allocations->pluck('payslip_id')->filter()->unique()->sort()->values()->all();
        $bonusIds = $allocations->pluck('employee_bonus_id')->filter()->unique()->sort()->values()->all();

        $payslips = Payslip::query()
            ->whereKey($payslipIds)->orderBy('id')->lockForUpdate()->get()->keyBy(
                fn (Payslip $slip): string => (string) $slip->getKey(),
            );

        $bonuses = EmployeeBonus::query()
            ->whereKey($bonusIds)->orderBy('id')->lockForUpdate()->get()->keyBy(
                fn (EmployeeBonus $bonus): string => (string) $bonus->getKey(),
            );

        $sum = '0.0000';

        // Accumulated per target, so two rows naming the same slip cannot
        // slide past a per-row check together.
        $perTarget = [];

        foreach ($allocations as $allocation) {
            $amount = $this->scale((string) $allocation->amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw VoucherRejected::amountNotPositive();
            }

            // Exactly one target per row.
            if (($allocation->payslip_id === null) === ($allocation->employee_bonus_id === null)) {
                throw VoucherRejected::targetInvalid();
            }

            if ($allocation->payslip_id !== null) {
                $slip = $payslips[(string) $allocation->payslip_id] ?? throw VoucherRejected::targetInvalid();

                if ($slip->employee_id !== $voucher->employee_id) {
                    throw VoucherRejected::crossEmployee();
                }

                $key = 'slip:'.$allocation->payslip_id;
                $perTarget[$key] = bcadd($perTarget[$key] ?? '0', $amount, self::SCALE);

                $open = $this->outstanding->payslipOutstanding($slip);

                if (bccomp($perTarget[$key], $open, self::SCALE) > 0) {
                    throw VoucherRejected::exceedsOutstanding(
                        $slip->period()->value('name') ?? '',
                        $open,
                    );
                }
            } else {
                $bonus = $bonuses[(string) $allocation->employee_bonus_id] ?? throw VoucherRejected::targetInvalid();

                if ($bonus->employee_id !== $voucher->employee_id) {
                    throw VoucherRejected::crossEmployee();
                }

                if (! $bonus->isApproved()) {
                    throw VoucherRejected::targetNotApproved();
                }

                $key = 'bonus:'.$allocation->employee_bonus_id;
                $perTarget[$key] = bcadd($perTarget[$key] ?? '0', $amount, self::SCALE);

                $open = $this->outstanding->bonusOutstanding($bonus);

                if (bccomp($perTarget[$key], $open, self::SCALE) > 0) {
                    throw VoucherRejected::exceedsOutstanding($bonus->reference, $open);
                }
            }

            $sum = bcadd($sum, $amount, self::SCALE);
        }

        return $sum;
    }

    private function scale(string $amount): string
    {
        return bcadd(trim($amount) === '' ? '0' : trim($amount), '0', self::SCALE);
    }
}
