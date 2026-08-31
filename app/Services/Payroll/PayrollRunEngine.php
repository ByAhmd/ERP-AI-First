<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\DocumentStatus;
use App\Enums\EmployeeCostType;
use App\Enums\EmployeeStatus;
use App\Enums\NationalityStatus;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentKind;
use App\Enums\SystemAccount;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeBonus;
use App\Models\EmployeeDeduction;
use App\Models\EmployeePaymentAllocation;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Models\PayslipAdvanceRecovery;
use App\Models\PayslipLine;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPoster;
use App\Services\Payroll\Exceptions\PayrollRunRejected;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The payroll run — مسير الرواتب, the module's single computing door.
 *
 * Everything resolves at approval, under locks, and stamps onto payslip
 * rows — the record of what actually posted, never a live recomputation.
 * Every figure is projected to currency scale the moment it is produced;
 * the net is derived from already-rounded parts, so the entry balances by
 * identity, not by a rounding line.
 *
 * Proration is calendar-day over the employee's eligible window — the
 * stand-in for Qoyod's schedule-hours method until the work-schedules
 * slice. The GOSI wage is the UNPRORATED contracted wage (base plus the
 * housing-flagged allowances, capped), because subscription follows the
 * contract, not the month's payout.
 *
 * The idempotency anchor is the payslips' unique (employee, period of
 * record): rows insert before the entry posts, so a concurrent duplicate
 * run dies on the index before any money moves. Employees already paid
 * for the period are skipped, which is what makes a supplementary run for
 * missed employees legal — Qoyod's own rule.
 */
final class PayrollRunEngine
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly FiscalCalendar $calendar,
    ) {}

    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'payroll_run',
            defaults: ['prefix' => 'PRN-', 'padding' => 5],
        ));
    }

    /**
     * What approving a draft run would pay — display only, no locks.
     *
     * @return array{total_net: string, rows: list<array<string, mixed>>}
     */
    public function preview(PayrollRun $run): array
    {
        $period = $run->period()->firstOrFail();

        $employees = $this->candidates($run, $period, lock: false);

        $rows = [];
        $totalNet = '0.0000';

        foreach ($employees as $employee) {
            $figures = $this->computeEmployee($employee, $period, PayrollSetting::current(), lock: false);

            if ($figures === null) {
                continue;
            }

            $rows[] = $figures;
            $totalNet = bcadd($totalNet, $figures['net'], self::SCALE);
        }

        return ['total_net' => $totalNet, 'rows' => $rows];
    }

    public function approve(PayrollRun $run, ?string $userId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $userId): PayrollRun {
            $locked = PayrollRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isApproved()) {
                throw PayrollRunRejected::alreadyApproved($locked->reference);
            }

            if (! $locked->isDraft()) {
                throw PayrollRunRejected::notDraft();
            }

            $period = $locked->period()->firstOrFail();

            // Loud on a closed period or a missing year — never redate.
            $this->calendar->resolveOpenPeriod($period->end_date);

            $settings = PayrollSetting::current();

            $employees = $this->candidates($locked, $period, lock: true);

            $computed = [];

            foreach ($employees as $employee) {
                $figures = $this->computeEmployee($employee, $period, $settings, lock: true);

                if ($figures !== null) {
                    $computed[] = $figures;
                }
            }

            if ($computed === []) {
                throw PayrollRunRejected::nothingToPay();
            }

            // Slips first: the unique (employee, period) index kills a
            // concurrent duplicate before any money moves.
            foreach ($computed as &$figures) {
                $payslip = Payslip::create([
                    'payroll_run_id' => $locked->getKey(),
                    'employee_id' => $figures['employee']->getKey(),
                    'accounting_period_id' => $period->getKey(),
                    'branch_id' => $figures['employee']->branch_id,
                    'cost_type' => $figures['employee']->cost_type,
                    'base_salary' => $figures['base'],
                    'allowances_total' => $figures['allowances_total'],
                    'bonuses_display_total' => $figures['bonuses_display_total'],
                    'deductions_total' => $figures['deductions_total'],
                    'advance_recovery' => $figures['advance_recovery'],
                    'gosi_wage' => $figures['gosi_wage'],
                    'gosi_employee' => $figures['gosi_employee'],
                    'gosi_employer' => $figures['gosi_employer'],
                    'gross' => $figures['gross'],
                    'net' => $figures['net'],
                ]);

                $figures['payslip'] = $payslip;

                foreach ($figures['lines'] as $line) {
                    PayslipLine::create([
                        'payslip_id' => $payslip->getKey(),
                        'kind' => $line['kind'],
                        'salary_component_id' => $line['salary_component_id'],
                        'source_type' => $line['source_type'],
                        'source_id' => $line['source_id'],
                        'label' => $line['label'],
                        'amount' => $line['amount'],
                    ]);
                }

                foreach ($figures['recoveries'] as $recovery) {
                    PayslipAdvanceRecovery::create([
                        'payslip_id' => $payslip->getKey(),
                        'employee_advance_id' => $recovery['advance_id'],
                        'amount' => $recovery['amount'],
                    ]);
                }

                if ($figures['deduction_ids'] !== []) {
                    EmployeeDeduction::query()
                        ->whereIn('id', $figures['deduction_ids'])
                        ->update(['payslip_id' => $payslip->getKey()]);
                }
            }

            unset($figures);

            $entry = $this->poster->post(
                date: $period->end_date,
                lines: $this->groupLines($computed),
                description: trim(__('payroll.runs.narration', [
                    'reference' => $locked->reference,
                    'period' => $period->name,
                ])),
                reference: $locked->reference,
                source: $locked,
                userId: $userId,
            );

            Payslip::query()
                ->where('payroll_run_id', $locked->getKey())
                ->update(['journal_entry_id' => $entry->getKey()]);

            $totals = $this->totals($computed);

            $locked->forceFill([
                'status' => DocumentStatus::Approved,
                'journal_entry_id' => $entry->getKey(),
                'run_date' => $period->end_date,
                'gross_total' => $totals['gross'],
                'allowances_total' => $totals['allowances'],
                'deductions_total' => $totals['deductions'],
                'advance_recovery_total' => $totals['recovery'],
                'gosi_employee_total' => $totals['gosi_employee'],
                'gosi_employer_total' => $totals['gosi_employer'],
                'net_total' => $totals['net'],
                'employees_count' => count($computed),
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Reverse an approved run — the module's replacement for a delete.
     *
     * The reversal entry, the payslip rows and the deduction marks drop
     * together, restoring advance balances by derivation and freeing the
     * period for a corrected run.
     */
    public function reverse(PayrollRun $run, CarbonImmutable $date, ?string $userId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $date, $userId): PayrollRun {
            $locked = PayrollRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw PayrollRunRejected::notApproved($locked->reference);
            }

            // Money already paid against these slips must be unwound first
            // — refusing beats Qoyod's silent delete-cascade.
            $allocated = EmployeePaymentAllocation::query()
                ->whereIn('payslip_id', $locked->payslips()->select('id'))
                ->exists();

            if ($allocated) {
                throw PayrollRunRejected::payslipsAllocated($locked->reference);
            }

            $reversal = $this->poster->reverse(
                original: $locked->journalEntry()->firstOrFail(),
                date: $date,
                userId: $userId,
            );

            // Free the consumed deductions explicitly, then drop the slips
            // — lines and recovery rows cascade with them.
            EmployeeDeduction::query()
                ->whereIn('payslip_id', $locked->payslips()->select('id'))
                ->update(['payslip_id' => null]);

            $locked->payslips()->delete();

            $locked->forceFill([
                'status' => DocumentStatus::Void,
                'reversal_journal_entry_id' => $reversal->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    // -----------------------------------------------------------------------

    /**
     * The employees a run may pay: active or terminated, window
     * intersecting the period, not excluded, not already paid for the
     * period of record.
     *
     * @return Collection<int, Employee>
     */
    private function candidates(PayrollRun $run, AccountingPeriod $period, bool $lock): Collection
    {
        $paid = Payslip::query()
            ->where('accounting_period_id', $period->getKey())
            ->pluck('employee_id')
            ->all();

        $query = Employee::query()
            ->whereIn('status', [EmployeeStatus::Active, EmployeeStatus::Terminated])
            ->whereNotIn('id', $run->exclusions()->select('employee_id'))
            ->when($paid !== [], fn ($q) => $q->whereNotIn('id', $paid))
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $start = CarbonImmutable::instance($period->start_date)->startOfDay();
        $end = CarbonImmutable::instance($period->end_date)->startOfDay();

        return $query->get()
            ->filter(fn (Employee $employee): bool => $employee->eligibleBetween($start, $end))
            ->values();
    }

    /**
     * One employee's figures for a period — every amount projected to
     * currency scale the moment it is produced.
     *
     * @return ?array<string, mixed> Null when the month carries nothing.
     */
    private function computeEmployee(
        Employee $employee,
        AccountingPeriod $period,
        PayrollSetting $settings,
        bool $lock,
    ): ?array {
        $periodStart = CarbonImmutable::instance($period->start_date)->startOfDay();
        $periodEnd = CarbonImmutable::instance($period->end_date)->startOfDay();

        $windowStart = CarbonImmutable::instance($employee->first_salary_date)->startOfDay()->max($periodStart);
        $windowEnd = $employee->last_salary_date === null
            ? $periodEnd
            : CarbonImmutable::instance($employee->last_salary_date)->startOfDay()->min($periodEnd);

        if ($windowStart->greaterThan($windowEnd)) {
            return null;
        }

        $periodDays = (int) $periodStart->diffInDays($periodEnd) + 1;
        $eligibleDays = (int) $windowStart->diffInDays($windowEnd) + 1;

        $fraction = BigRational::of($eligibleDays)->dividedBy($periodDays);

        $base = $this->round2(BigRational::of($this->scale((string) $employee->base_salary))->multipliedBy($fraction));

        $lines = [[
            'kind' => 'base',
            'salary_component_id' => null,
            'source_type' => null,
            'source_id' => null,
            'label' => __('payroll.slip.base'),
            'amount' => $base,
        ]];

        // Components: allowances add, recurring deductions subtract — both
        // prorated when fixed, percent-of-prorated-base when percent.
        $assignments = $employee->salaryComponents()->with('component')->get();

        $allowancesTotal = '0.0000';
        $recurringDeductions = '0.0000';
        $allowanceByAccount = [];
        $deductionByAccount = [];
        $gosiExtras = '0.0000';

        foreach ($assignments as $assignment) {
            $component = $assignment->component;

            if ($component === null) {
                continue;
            }

            $amount = $component->calculation === SalaryComponentCalculation::Fixed
                ? $this->round2(BigRational::of($this->scale((string) $assignment->amount))->multipliedBy($fraction))
                : $this->round2(BigRational::of($this->scale((string) $assignment->amount))
                    ->dividedBy(100)
                    ->multipliedBy(BigRational::of($base)));

            if (bccomp($amount, '0', self::SCALE) === 0) {
                continue;
            }

            if ($component->kind === SalaryComponentKind::Allowance) {
                $allowancesTotal = bcadd($allowancesTotal, $amount, self::SCALE);
                $key = (string) $component->account_id;
                $allowanceByAccount[$key] = bcadd($allowanceByAccount[$key] ?? '0', $amount, self::SCALE);
            } else {
                $recurringDeductions = bcadd($recurringDeductions, $amount, self::SCALE);
                $key = (string) $component->account_id;
                $deductionByAccount[$key] = bcadd($deductionByAccount[$key] ?? '0', $amount, self::SCALE);
            }

            $lines[] = [
                'kind' => $component->kind === SalaryComponentKind::Allowance ? 'allowance' : 'deduction',
                'salary_component_id' => $component->getKey(),
                'source_type' => null,
                'source_id' => null,
                'label' => $component->displayName(),
                'amount' => $amount,
            ];

            // The GOSI wage counts the FULL-month figure of flagged
            // allowances — subscription follows the contract.
            if ($component->kind === SalaryComponentKind::Allowance && $component->counts_toward_gosi) {
                $full = $component->calculation === SalaryComponentCalculation::Fixed
                    ? $this->scale((string) $assignment->amount)
                    : $this->round2(BigRational::of($this->scale((string) $assignment->amount))
                        ->dividedBy(100)
                        ->multipliedBy(BigRational::of($this->scale((string) $employee->base_salary))));

                $gosiExtras = bcadd($gosiExtras, $full, self::SCALE);
            }
        }

        $gross = bcadd($base, $allowancesTotal, self::SCALE);

        // GOSI on the unprorated contracted wage, capped.
        $gosiWage = '0.0000';
        $gosiEmployee = '0.0000';
        $gosiEmployer = '0.0000';

        if ($employee->gosi_enrolled) {
            $gosiWage = $employee->gosi_wage !== null
                ? $this->scale((string) $employee->gosi_wage)
                : bcadd($this->scale((string) $employee->base_salary), $gosiExtras, self::SCALE);

            $ceiling = $this->scale((string) $settings->gosi_wage_ceiling);

            if (bccomp($gosiWage, $ceiling, self::SCALE) > 0) {
                $gosiWage = $ceiling;
            }

            if ($employee->nationality_status === NationalityStatus::Saudi) {
                $gosiEmployee = $this->rate($gosiWage, (string) $settings->saudi_employee_rate);
                $gosiEmployer = $this->rate($gosiWage, (string) $settings->saudi_employer_rate);
            } else {
                $gosiEmployer = $this->rate($gosiWage, (string) $settings->non_saudi_employer_rate);
            }

            if (bccomp($gosiEmployee, '0', self::SCALE) > 0) {
                $lines[] = [
                    'kind' => 'gosi_employee',
                    'salary_component_id' => null,
                    'source_type' => null,
                    'source_id' => null,
                    'label' => __('payroll.slip.gosi_employee'),
                    'amount' => $gosiEmployee,
                ];
            }
        }

        // One-off deductions: approved, unconsumed, dated in or before the
        // period — earlier unconsumed ones roll forward.
        $deductionQuery = EmployeeDeduction::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', DocumentStatus::Approved)
            ->whereNull('payslip_id')
            ->whereDate('deduction_date', '<=', $period->end_date)
            ->orderBy('id');

        if ($lock) {
            $deductionQuery->lockForUpdate();
        }

        $oneOffDeductions = $deductionQuery->get();

        $oneOffTotal = '0.0000';
        $deductionIds = [];

        foreach ($oneOffDeductions as $deduction) {
            $amount = $this->scale((string) $deduction->amount);
            $oneOffTotal = bcadd($oneOffTotal, $amount, self::SCALE);
            $deductionIds[] = $deduction->getKey();

            $lines[] = [
                'kind' => 'deduction',
                'salary_component_id' => null,
                'source_type' => $deduction->getMorphClass(),
                'source_id' => $deduction->getKey(),
                'label' => $deduction->kind->getLabel(),
                'amount' => $amount,
            ];
        }

        $deductionsTotal = bcadd($recurringDeductions, $oneOffTotal, self::SCALE);

        $netBeforeRecovery = bcsub(
            bcsub($gross, $gosiEmployee, self::SCALE),
            $deductionsTotal,
            self::SCALE,
        );

        // Deductions overflowing the month are a data problem the user
        // must see — never a silent clamp.
        if (bccomp($netBeforeRecovery, '0', self::SCALE) < 0) {
            throw PayrollRunRejected::netBelowZero($employee->fullName());
        }

        // Advance recovery: clamped to the remaining balances and to the
        // net, consumed oldest-first.
        $advanceQuery = EmployeeAdvance::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', DocumentStatus::Approved)
            ->orderBy('advance_date')
            ->orderBy('id');

        if ($lock) {
            $advanceQuery->lockForUpdate();
        }

        $recoveries = [];
        $recoveryTotal = '0.0000';
        $available = $netBeforeRecovery;

        foreach ($advanceQuery->get() as $advance) {
            if (bccomp($available, '0', self::SCALE) <= 0) {
                break;
            }

            $remaining = $advance->remaining();

            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                continue;
            }

            $take = bccomp($remaining, $available, self::SCALE) > 0 ? $available : $remaining;

            $recoveries[] = ['advance_id' => $advance->getKey(), 'amount' => $take];
            $recoveryTotal = bcadd($recoveryTotal, $take, self::SCALE);
            $available = bcsub($available, $take, self::SCALE);

            $lines[] = [
                'kind' => 'advance_recovery',
                'salary_component_id' => null,
                'source_type' => $advance->getMorphClass(),
                'source_id' => $advance->getKey(),
                'label' => __('payroll.slip.advance_recovery', ['reference' => $advance->reference]),
                'amount' => $take,
            ];
        }

        // Net derives from already-rounded parts — never re-rounded.
        $net = bcsub($netBeforeRecovery, $recoveryTotal, self::SCALE);

        // Bonuses show on the slip; they never enter net — their approval
        // already accrued the payable.
        $bonuses = EmployeeBonus::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', DocumentStatus::Approved)
            ->whereDate('bonus_date', '>=', $period->start_date)
            ->whereDate('bonus_date', '<=', $period->end_date)
            ->get();

        $bonusesTotal = '0.0000';

        foreach ($bonuses as $bonus) {
            $amount = $this->scale((string) $bonus->amount);
            $bonusesTotal = bcadd($bonusesTotal, $amount, self::SCALE);

            $lines[] = [
                'kind' => 'bonus',
                'salary_component_id' => null,
                'source_type' => $bonus->getMorphClass(),
                'source_id' => $bonus->getKey(),
                'label' => $bonus->kind->getLabel(),
                'amount' => $amount,
            ];
        }

        // A month that carries nothing at all writes no slip.
        if (bccomp($gross, '0', self::SCALE) === 0
            && bccomp($deductionsTotal, '0', self::SCALE) === 0
            && bccomp($recoveryTotal, '0', self::SCALE) === 0) {
            return null;
        }

        return [
            'employee' => $employee,
            'base' => $base,
            'allowances_total' => $allowancesTotal,
            'allowance_by_account' => $allowanceByAccount,
            'deduction_by_account' => $deductionByAccount,
            'one_off_deductions' => $oneOffTotal,
            'deductions_total' => $deductionsTotal,
            'deduction_ids' => $deductionIds,
            'advance_recovery' => $recoveryTotal,
            'recoveries' => $recoveries,
            'gosi_wage' => $gosiWage,
            'gosi_employee' => $gosiEmployee,
            'gosi_employer' => $gosiEmployer,
            'gross' => $gross,
            'net' => $net,
            'bonuses_display_total' => $bonusesTotal,
            'lines' => $lines,
        ];
    }

    /**
     * The run's entry: one line pair vocabulary, grouped by
     * (account, branch) exactly like the depreciation engine.
     *
     * @param  list<array<string, mixed>>  $computed
     * @return list<JournalLineData>
     */
    private function groupLines(array $computed): array
    {
        /** @var array<string, string> $debits */
        $debits = [];
        /** @var array<string, string> $credits */
        $credits = [];

        $add = function (array &$side, string $accountId, ?string $branchId, string $amount): void {
            if (bccomp($amount, '0', self::SCALE) === 0) {
                return;
            }

            $key = $accountId.'|'.($branchId ?? '');
            $side[$key] = bcadd($side[$key] ?? '0', $amount, self::SCALE);
        };

        $salaries = $this->registry->get(SystemAccount::SalariesExpense)->getKey();
        $directSalaries = $this->registry->get(SystemAccount::DirectSalariesExpense)->getKey();
        $gosiExpense = $this->registry->get(SystemAccount::GosiExpense)->getKey();
        $gosiPayable = $this->registry->get(SystemAccount::GosiPayable)->getKey();
        $advances = $this->registry->get(SystemAccount::EmployeeAdvances)->getKey();
        $deductionsIncome = $this->registry->get(SystemAccount::EmployeeDeductionsIncome)->getKey();
        $payable = $this->registry->get(SystemAccount::SalariesPayable)->getKey();

        foreach ($computed as $figures) {
            /** @var Employee $employee */
            $employee = $figures['employee'];
            $branch = $employee->branch_id;

            $add($debits, $employee->cost_type === EmployeeCostType::Direct ? $directSalaries : $salaries, $branch, $figures['base']);

            foreach ($figures['allowance_by_account'] as $accountId => $amount) {
                $add($debits, (string) $accountId, $branch, $amount);
            }

            $add($debits, $gosiExpense, $branch, $figures['gosi_employer']);

            foreach ($figures['deduction_by_account'] as $accountId => $amount) {
                $add($credits, (string) $accountId, $branch, $amount);
            }

            $add($credits, $deductionsIncome, $branch, $figures['one_off_deductions']);
            $add($credits, $advances, $branch, $figures['advance_recovery']);
            $add($credits, $gosiPayable, $branch, bcadd($figures['gosi_employee'], $figures['gosi_employer'], self::SCALE));
            $add($credits, $payable, $branch, $figures['net']);
        }

        $lines = [];

        foreach ($debits as $key => $amount) {
            [$accountId, $branchId] = explode('|', $key);
            $lines[] = JournalLineData::debit($accountId, $amount)
                ->withBranch($branchId === '' ? null : $branchId);
        }

        foreach ($credits as $key => $amount) {
            [$accountId, $branchId] = explode('|', $key);
            $lines[] = JournalLineData::credit($accountId, $amount)
                ->withBranch($branchId === '' ? null : $branchId);
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $computed
     * @return array<string, string>
     */
    private function totals(array $computed): array
    {
        $totals = [
            'gross' => '0.0000', 'allowances' => '0.0000', 'deductions' => '0.0000',
            'recovery' => '0.0000', 'gosi_employee' => '0.0000',
            'gosi_employer' => '0.0000', 'net' => '0.0000',
        ];

        foreach ($computed as $figures) {
            $totals['gross'] = bcadd($totals['gross'], $figures['gross'], self::SCALE);
            $totals['allowances'] = bcadd($totals['allowances'], $figures['allowances_total'], self::SCALE);
            $totals['deductions'] = bcadd($totals['deductions'], $figures['deductions_total'], self::SCALE);
            $totals['recovery'] = bcadd($totals['recovery'], $figures['advance_recovery'], self::SCALE);
            $totals['gosi_employee'] = bcadd($totals['gosi_employee'], $figures['gosi_employee'], self::SCALE);
            $totals['gosi_employer'] = bcadd($totals['gosi_employer'], $figures['gosi_employer'], self::SCALE);
            $totals['net'] = bcadd($totals['net'], $figures['net'], self::SCALE);
        }

        return $totals;
    }

    private function rate(string $amount, string $percent): string
    {
        return $this->round2(
            BigRational::of($amount)
                ->multipliedBy(BigRational::of($this->scale($percent)))
                ->dividedBy(100),
        );
    }

    private function round2(BigRational $amount): string
    {
        return bcadd((string) $amount->toScale(2, RoundingMode::HalfUp), '0', self::SCALE);
    }

    private function scale(string $amount): string
    {
        return bcadd(trim($amount) === '' ? '0' : trim($amount), '0', self::SCALE);
    }
}
