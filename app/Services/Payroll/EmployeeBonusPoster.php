<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\DocumentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\SystemAccount;
use App\Models\EmployeeBonus;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Payroll\Exceptions\PayrollRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bonuses — Qoyod's مكافأة, its own accrual.
 *
 * Approval posts DR مكافآت الموظفين / CR رواتب مستحقة, dated the bonus.
 * The payroll run therefore never adds bonuses into net — they already
 * stand on the payable, and a voucher settles them directly. Including
 * them again would credit 2140 twice for the same money.
 */
final class EmployeeBonusPoster
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
            key: 'employee_bonus',
            defaults: ['prefix' => 'BON-', 'padding' => 5],
        ));
    }

    public function approve(EmployeeBonus $bonus, ?string $userId = null): EmployeeBonus
    {
        return DB::transaction(function () use ($bonus, $userId): EmployeeBonus {
            $locked = EmployeeBonus::query()
                ->whereKey($bonus->getKey())
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

            if ($employee->status === EmployeeStatus::Archived) {
                throw PayrollRuleViolation::employeeNotPayable($employee->fullName());
            }

            $amount = $this->scale((string) $locked->amount);

            $entry = $this->poster->post(
                date: $locked->bonus_date,
                lines: array_map(
                    fn (JournalLineData $line): JournalLineData => $line->withBranch($employee->branch_id),
                    [
                        JournalLineData::debit(
                            $this->registry->get(SystemAccount::BonusesExpense)->getKey(),
                            $amount,
                        ),
                        JournalLineData::credit(
                            $this->registry->get(SystemAccount::SalariesPayable)->getKey(),
                            $amount,
                        ),
                    ],
                ),
                description: trim(__('payroll.bonuses.narration', [
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
     * Undo an approved bonus nothing has paid yet.
     */
    public function reverse(EmployeeBonus $bonus, CarbonImmutable $date, ?string $userId = null): EmployeeBonus
    {
        return DB::transaction(function () use ($bonus, $date, $userId): EmployeeBonus {
            $locked = EmployeeBonus::query()
                ->whereKey($bonus->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw PayrollRuleViolation::notApproved($locked->reference);
            }

            // A paid bonus is settled history — unwind the voucher first.
            if ($locked->allocations()->exists()) {
                throw PayrollRuleViolation::bonusHasAllocations($locked->reference);
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

    private function scale(string $amount): string
    {
        return bcadd(trim($amount) === '' ? '0' : trim($amount), '0', self::SCALE);
    }
}
