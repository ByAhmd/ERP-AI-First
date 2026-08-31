<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Enums\DocumentStatus;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeDeduction;
use App\Models\Payslip;
use App\Services\Payroll\EmployeeAdvancePoster;
use App\Services\Payroll\PayrollRunEngine;
use App\Services\Payroll\Exceptions\PayrollRunRejected;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The run engine's arithmetic and its anchors.
 */
final class PayrollRunTest extends PayrollTestCase
{
    #[Test]
    public function the_run_computes_gosi_both_ways_and_groups_by_branch_and_cost_type(): void
    {
        $second = \App\Models\Branch::create(['code' => 'B2', 'name' => 'فرع جدة']);

        $housing = $this->makeComponent('بدل سكن', gosi: true, accountCode: '5300');
        $transport = $this->makeComponent('بدل نقل', accountCode: '5400');

        // Saudi, enrolled, indirect: both GOSI shares on base + housing.
        $saudi = $this->makeEmployee(['gosi_enrolled' => true]);
        $this->assign($saudi, $housing, '2000');
        $this->assign($saudi, $transport, '500');

        // Non-Saudi, enrolled, direct labor at the second branch: only the
        // employer's share.
        $foreign = $this->makeEmployee([
            'nationality_status' => 'non_saudi',
            'cost_type' => 'direct',
            'branch_id' => $second->getKey(),
            'base_salary' => '7000',
            'gosi_enrolled' => true,
        ]);

        // Saudi, NOT enrolled: no GOSI at all.
        $unenrolled = $this->makeEmployee(['base_salary' => '3000']);

        $run = $this->approveRun('2026-01-15');

        $slips = Payslip::query()->get()->keyBy('employee_id');

        // 12,000 wage: employee 9.75% = 1,170, employer 11.75% = 1,410.
        $slip = $slips[$saudi->getKey()];
        $this->assertSame(0, bccomp('12000', (string) $slip->gosi_wage, 4));
        $this->assertSame(0, bccomp('1170', (string) $slip->gosi_employee, 4));
        $this->assertSame(0, bccomp('1410', (string) $slip->gosi_employer, 4));
        $this->assertSame(0, bccomp('12500', (string) $slip->gross, 4));
        $this->assertSame(0, bccomp('11330', (string) $slip->net, 4));

        // 7,000 wage: employer 2% = 140, employee nothing.
        $slip = $slips[$foreign->getKey()];
        $this->assertSame(0, bccomp('0', (string) $slip->gosi_employee, 4));
        $this->assertSame(0, bccomp('140', (string) $slip->gosi_employer, 4));
        $this->assertSame(0, bccomp('7000', (string) $slip->net, 4));

        // Unenrolled: zero everywhere.
        $slip = $slips[$unenrolled->getKey()];
        $this->assertSame(0, bccomp('0', (string) $slip->gosi_employer, 4));
        $this->assertSame(0, bccomp('3000', (string) $slip->net, 4));

        // GL: expense split by cost type, GOSI expense = employer shares
        // only, both shares on the liability, net on the payable.
        $this->assertSame(0, bccomp('13000', $this->balanceOf($this->accountByKey('salaries_expense')), 4));
        $this->assertSame(0, bccomp('7000', $this->balanceOf($this->accountByKey('direct_salaries_expense')), 4));
        $this->assertSame(0, bccomp('1550', $this->balanceOf($this->accountByKey('gosi_expense')), 4));
        $this->assertSame(0, bccomp('-2720', $this->balanceOf($this->accountByKey('gosi_payable')), 4));
        $this->assertSame(0, bccomp('-21330', $this->balanceOf($this->accountByKey('salaries_payable')), 4));

        // The entry balances by identity and every line carries a branch.
        $entry = $run->journalEntry()->firstOrFail();
        $this->assertSame(0, bccomp((string) $entry->total_debit, (string) $entry->total_credit, 4));

        foreach ($entry->lines()->get() as $line) {
            $this->assertNotNull($line->branch_id);
        }

        // The run accrual is dated the period's last day.
        $this->assertSame('2026-01-31', $entry->entry_date->format('Y-m-d'));
    }

    #[Test]
    public function the_gosi_wage_respects_the_ceiling_and_percent_allowances_prorate(): void
    {
        $percent = $this->makeComponent('عمولة ثابتة', calculation: 'percent_of_base', accountCode: '5300');

        $rich = $this->makeEmployee(['base_salary' => '50000', 'gosi_enrolled' => true]);
        $this->assign($rich, $percent, '10');

        $this->approveRun('2026-01-15');

        $slip = Payslip::query()->sole();

        // Wage capped at 45,000: employee 4,387.50, employer 5,287.50.
        $this->assertSame(0, bccomp('45000', (string) $slip->gosi_wage, 4));
        $this->assertSame(0, bccomp('4387.50', (string) $slip->gosi_employee, 4));
        $this->assertSame(0, bccomp('5287.50', (string) $slip->gosi_employer, 4));

        // Percent of base: 10% of 50,000.
        $this->assertSame(0, bccomp('5000', (string) $slip->allowances_total, 4));
    }

    #[Test]
    public function hires_and_terminations_prorate_by_day(): void
    {
        $this->makeEmployee(['first_salary_date' => '2026-01-16']);
        $leaver = $this->makeEmployee([
            'first_salary_date' => '2026-01-01',
            'last_salary_date' => '2026-01-20',
            'status' => 'terminated',
        ]);

        $this->approveRun('2026-01-15');

        $slips = Payslip::query()->get();

        // 16 of 31 days = 5,161.29; 20 of 31 days = 6,451.61.
        $amounts = $slips->pluck('base_salary')->map(fn ($v): string => (string) $v)->sort()->values();

        $this->assertSame(0, bccomp('5161.29', $amounts[0], 4));
        $this->assertSame(0, bccomp('6451.61', $amounts[1], 4));

        // The leaver is out of range entirely next month.
        $this->makeEmployee(['base_salary' => '1000']);

        $this->approveRun('2026-02-15');

        $this->assertSame(
            0,
            Payslip::query()
                ->where('employee_id', $leaver->getKey())
                ->whereHas('period', fn ($q) => $q->whereDate('start_date', '2026-02-01'))
                ->count(),
        );
    }

    #[Test]
    public function an_employee_already_paid_for_the_period_is_skipped_and_the_index_guards_the_race(): void
    {
        $paid = $this->makeEmployee();

        $first = $this->approveRun('2026-01-15');
        $this->assertSame(1, $first->payslips()->count());

        // A supplementary run pays only the newcomer.
        $newcomer = $this->makeEmployee(['base_salary' => '4000']);

        $second = $this->approveRun('2026-01-15');

        $this->assertSame(1, $second->payslips()->count());
        $this->assertSame($newcomer->getKey(), $second->payslips()->sole()->employee_id);

        // And the database itself refuses a duplicate slip — the race
        // anchor beneath the skip.
        $this->expectException(UniqueConstraintViolationException::class);

        Payslip::create([
            'payroll_run_id' => $first->getKey(),
            'employee_id' => $paid->getKey(),
            'accounting_period_id' => $this->periodContaining('2026-01-15')->getKey(),
            'branch_id' => $this->branch->getKey(),
            'cost_type' => 'indirect',
            'base_salary' => '1',
            'gross' => '1',
            'net' => '1',
        ]);
    }

    #[Test]
    public function deductions_are_consumed_once_and_overflow_rejects_loudly(): void
    {
        $employee = $this->makeEmployee(['base_salary' => '5000']);

        EmployeeDeduction::create([
            'reference' => 'DED-00001',
            'employee_id' => $employee->getKey(),
            'kind' => 'violation',
            'amount' => '400',
            'deduction_date' => '2026-01-10',
            'status' => DocumentStatus::Approved,
        ]);

        $run = $this->approveRun('2026-01-15');

        $slip = Payslip::query()->sole();
        $this->assertSame(0, bccomp('400', (string) $slip->deductions_total, 4));
        $this->assertSame(0, bccomp('4600', (string) $slip->net, 4));

        $deduction = EmployeeDeduction::query()->sole();
        $this->assertSame($slip->getKey(), $deduction->payslip_id);

        // Deductions recovered from staff are income.
        $this->assertSame(0, bccomp('-400', $this->balanceOf($this->accountByKey('employee_deductions_income')), 4));

        // February: the consumed deduction never fires again.
        $this->approveRun('2026-02-15');

        $february = Payslip::query()
            ->whereHas('period', fn ($q) => $q->whereDate('start_date', '2026-02-01'))
            ->sole();

        $this->assertSame(0, bccomp('0', (string) $february->deductions_total, 4));

        // An overflowing deduction is a loud refusal naming the month's
        // problem, never a silent clamp.
        $poor = $this->makeEmployee(['base_salary' => '300', 'first_salary_date' => '2026-03-01']);

        EmployeeDeduction::create([
            'reference' => 'DED-00002',
            'employee_id' => $poor->getKey(),
            'kind' => 'other',
            'amount' => '900',
            'deduction_date' => '2026-03-05',
            'status' => DocumentStatus::Approved,
        ]);

        $this->expectException(PayrollRunRejected::class);

        $this->approveRun('2026-03-15');
    }

    #[Test]
    public function advance_recovery_clamps_to_net_and_to_remaining_oldest_first(): void
    {
        $employee = $this->makeEmployee(['base_salary' => '3000']);

        $advance = EmployeeAdvance::create([
            'reference' => 'ADV-00001',
            'employee_id' => $employee->getKey(),
            'kind' => 'advance',
            'amount' => '5000',
            'advance_date' => '2026-01-05',
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
        ]);

        app(EmployeeAdvancePoster::class)->approve($advance);

        // January: the whole net goes to recovery, clamped by the month.
        $this->approveRun('2026-01-15');

        $slip = Payslip::query()->sole();
        $this->assertSame(0, bccomp('3000', (string) $slip->advance_recovery, 4));
        $this->assertSame(0, bccomp('0', (string) $slip->net, 4));
        $this->assertSame(0, bccomp('2000', $advance->refresh()->remaining(), 4));

        // February: only the remaining 2,000 comes back.
        $this->approveRun('2026-02-15');

        $february = Payslip::query()
            ->whereHas('period', fn ($q) => $q->whereDate('start_date', '2026-02-01'))
            ->sole();

        $this->assertSame(0, bccomp('2000', (string) $february->advance_recovery, 4));
        $this->assertSame(0, bccomp('1000', (string) $february->net, 4));
        $this->assertSame(0, bccomp('0', $advance->refresh()->remaining(), 4));

        // 1180 is clean: issued 5,000, recovered 5,000.
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByKey('employee_advances')), 4));
    }

    #[Test]
    public function a_run_into_a_closed_period_fails_loudly(): void
    {
        $this->makeEmployee();

        $this->periodContaining('2026-01-15')
            ->forceFill(['status' => \App\Enums\PeriodStatus::Closed])->save();

        $this->expectException(\App\Services\Accounting\Exceptions\PostingRejected::class);

        $this->approveRun('2026-01-15');
    }

    #[Test]
    public function reversal_drops_slips_and_money_together_and_frees_everything(): void
    {
        $employee = $this->makeEmployee(['base_salary' => '5000']);

        EmployeeDeduction::create([
            'reference' => 'DED-00001',
            'employee_id' => $employee->getKey(),
            'kind' => 'violation',
            'amount' => '400',
            'deduction_date' => '2026-01-10',
            'status' => DocumentStatus::Approved,
        ]);

        $run = $this->approveRun('2026-01-15');

        app(PayrollRunEngine::class)->reverse($run, CarbonImmutable::parse('2026-01-31'));

        $this->assertSame(0, Payslip::query()->count());
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByKey('salaries_payable')), 4));
        $this->assertNull(EmployeeDeduction::query()->sole()->payslip_id);
        $this->assertSame(DocumentStatus::Void, $run->refresh()->status);

        // The period is free again — the corrected run reclaims it whole.
        $again = $this->approveRun('2026-01-15');

        $this->assertSame(1, $again->payslips()->count());
        $this->assertSame(0, bccomp('-4600', $this->balanceOf($this->accountByKey('salaries_payable')), 4));
        $this->assertSame($again->payslips()->sole()->getKey(), EmployeeDeduction::query()->sole()->payslip_id);
    }

    #[Test]
    public function an_empty_run_refuses(): void
    {
        $this->expectException(PayrollRunRejected::class);

        $this->approveRun('2026-01-15');
    }
}
