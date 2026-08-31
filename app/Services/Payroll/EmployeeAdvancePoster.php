<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\DocumentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceSettlement;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Payroll\Exceptions\PayrollRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Employee advances: issuing them, taking cash back, and undoing a
 * mistake before any money returned.
 *
 * Issuance: DR سلف الموظفين 1180 / CR the payment account. Cash
 * settlement mirrors it, guarded to the remaining balance under the
 * advance's lock — payroll recovery is the run's job, never entered here.
 * Reversal is refused the moment any repayment exists: the Qoyod rule
 * that a partly-repaid advance is history.
 */
final class EmployeeAdvancePoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'employee_advance',
            defaults: ['prefix' => 'ADV-', 'padding' => 5],
        ));
    }

    public function approve(EmployeeAdvance $advance, ?string $userId = null): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $userId): EmployeeAdvance {
            $locked = EmployeeAdvance::query()
                ->whereKey($advance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isApproved()) {
                throw PayrollRuleViolation::alreadyApproved($locked->reference);
            }

            if (! $locked->isDraft()) {
                throw PayrollRuleViolation::notDraft();
            }

            if (bccomp($this->scale((string) $locked->amount), '0', self::SCALE) <= 0) {
                throw PayrollRuleViolation::amountNotPositive();
            }

            $employee = $locked->employee()->firstOrFail();

            if ($employee->status !== EmployeeStatus::Active) {
                throw PayrollRuleViolation::employeeNotPayable($employee->fullName());
            }

            $account = $locked->paymentAccount()->first();
            $this->guardPaymentAccount($account);

            $entry = $this->poster->post(
                date: $locked->advance_date,
                lines: array_map(
                    fn (JournalLineData $line): JournalLineData => $line->withBranch($employee->branch_id),
                    [
                        JournalLineData::debit(
                            $this->registry->get(SystemAccount::EmployeeAdvances)->getKey(),
                            $this->scale((string) $locked->amount),
                        ),
                        JournalLineData::credit(
                            (string) $account?->getKey(),
                            $this->scale((string) $locked->amount),
                        ),
                    ],
                ),
                description: trim(__('payroll.advances.narration', [
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
     * Cash repayment — Qoyod's الدفع on the advance.
     */
    public function settle(
        EmployeeAdvance $advance,
        string $amount,
        CarbonImmutable $date,
        string $paymentAccountId,
        ?string $userId = null,
    ): EmployeeAdvanceSettlement {
        return DB::transaction(function () use ($advance, $amount, $date, $paymentAccountId, $userId): EmployeeAdvanceSettlement {
            $locked = EmployeeAdvance::query()
                ->whereKey($advance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw PayrollRuleViolation::notApproved($locked->reference);
            }

            $amount = $this->scale($amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw PayrollRuleViolation::amountNotPositive();
            }

            // Read under the advance's lock — two concurrent settlements
            // must serialise on the remaining balance.
            $remaining = $locked->remaining();

            if (bccomp($amount, $remaining, self::SCALE) > 0) {
                throw PayrollRuleViolation::settlementExceedsRemaining($locked->reference, $remaining);
            }

            $account = Account::query()->findOrFail($paymentAccountId);
            $this->guardPaymentAccount($account);

            $employee = $locked->employee()->firstOrFail();

            $settlement = EmployeeAdvanceSettlement::create([
                'employee_advance_id' => $locked->getKey(),
                'amount' => $amount,
                'settled_on' => $date->format('Y-m-d'),
                'payment_account_id' => $account->getKey(),
                'created_by_id' => $userId,
            ]);

            $entry = $this->poster->post(
                date: $date,
                lines: array_map(
                    fn (JournalLineData $line): JournalLineData => $line->withBranch($employee->branch_id),
                    [
                        JournalLineData::debit($account->getKey(), $amount),
                        JournalLineData::credit(
                            $this->registry->get(SystemAccount::EmployeeAdvances)->getKey(),
                            $amount,
                        ),
                    ],
                ),
                description: trim(__('payroll.advances.settlement_narration', [
                    'reference' => $locked->reference,
                    'employee' => $employee->fullName(),
                ])),
                reference: $locked->reference,
                source: $settlement,
                userId: $userId,
            );

            $settlement->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            return $settlement->refresh();
        });
    }

    /**
     * Undo an approved advance no money ever came back on.
     */
    public function reverse(EmployeeAdvance $advance, CarbonImmutable $date, ?string $userId = null): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $date, $userId): EmployeeAdvance {
            $locked = EmployeeAdvance::query()
                ->whereKey($advance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw PayrollRuleViolation::notApproved($locked->reference);
            }

            if ($locked->hasRepayments()) {
                throw PayrollRuleViolation::advanceHasRepayments($locked->reference);
            }

            $reversal = $this->poster->reverse(
                original: $locked->journalEntry()->firstOrFail(),
                date: $date,
                userId: $userId,
            );

            $locked->forceFill([
                'status' => DocumentStatus::Void,
                'reversal_journal_entry_id' => $reversal->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function guardPaymentAccount(?Account $account): void
    {
        if ($account === null || ! $account->acceptsPostings() || ! $account->is_payment_account) {
            throw PayrollRuleViolation::paymentAccountInvalid($account);
        }
    }

    private function scale(string $amount): string
    {
        return bcadd(trim($amount) === '' ? '0' : trim($amount), '0', self::SCALE);
    }
}
