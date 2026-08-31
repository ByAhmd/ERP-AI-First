<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Enums\DocumentStatus;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceSettlement;
use App\Models\EmployeeBonus;
use App\Models\EmployeePaymentVoucher;
use App\Models\PayrollRun;
use App\Services\Accounting\SubledgerSourceTypes;
use App\Services\Payroll\EmployeeAdvancePoster;
use App\Services\Payroll\EmployeeBonusPoster;
use App\Services\Payroll\Exceptions\PayrollRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The advance and bonus documents, and the plumbing beneath the module.
 */
final class AdvanceAndBonusTest extends PayrollTestCase
{
    private function makeAdvance(string $amount = '5000'): EmployeeAdvance
    {
        $employee = $this->makeEmployee();

        return EmployeeAdvance::create([
            'reference' => app(EmployeeAdvancePoster::class)->nextReference(),
            'employee_id' => $employee->getKey(),
            'kind' => 'advance',
            'amount' => $amount,
            'advance_date' => '2026-01-05',
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
        ]);
    }

    #[Test]
    public function an_advance_issues_settles_and_stays_tied(): void
    {
        $advance = $this->makeAdvance();

        app(EmployeeAdvancePoster::class)->approve($advance);

        // DR 1180 / CR bank.
        $this->assertSame(0, bccomp('5000', $this->balanceOf($this->accountByKey('employee_advances')), 4));
        $this->assertSame(0, bccomp('5000', $advance->refresh()->remaining(), 4));

        app(EmployeeAdvancePoster::class)->settle(
            $advance, '2000', CarbonImmutable::parse('2026-02-01'),
            $this->accountByCode('1110')->getKey(),
        );

        $this->assertSame(0, bccomp('3000', $this->balanceOf($this->accountByKey('employee_advances')), 4));
        $this->assertSame(0, bccomp('3000', $advance->refresh()->remaining(), 4));

        // A settlement past the balance is refused under the lock.
        try {
            app(EmployeeAdvancePoster::class)->settle(
                $advance, '3500', CarbonImmutable::parse('2026-02-10'),
                $this->accountByCode('1110')->getKey(),
            );
            $this->fail('A settlement exceeded the remaining balance.');
        } catch (PayrollRuleViolation) {
        }

        // And once money came back, the advance is history — no reverse.
        try {
            app(EmployeeAdvancePoster::class)->reverse($advance, CarbonImmutable::parse('2026-02-15'));
            $this->fail('A partly-repaid advance was reversed.');
        } catch (PayrollRuleViolation) {
        }

        $this->assertSame(1, EmployeeAdvanceSettlement::query()->count());
    }

    #[Test]
    public function an_untouched_advance_reverses_cleanly(): void
    {
        $advance = $this->makeAdvance();

        app(EmployeeAdvancePoster::class)->approve($advance);
        app(EmployeeAdvancePoster::class)->reverse($advance, CarbonImmutable::parse('2026-01-31'));

        $this->assertSame(DocumentStatus::Void, $advance->refresh()->status);
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByKey('employee_advances')), 4));
    }

    #[Test]
    public function a_bonus_accrues_the_payable_at_its_own_approval(): void
    {
        $employee = $this->makeEmployee();

        $bonus = EmployeeBonus::create([
            'reference' => app(EmployeeBonusPoster::class)->nextReference(),
            'employee_id' => $employee->getKey(),
            'kind' => 'grant',
            'amount' => '2000',
            'bonus_date' => '2026-01-20',
        ]);

        app(EmployeeBonusPoster::class)->approve($bonus);

        $this->assertSame(0, bccomp('2000', $this->balanceOf($this->accountByKey('bonuses_expense')), 4));
        $this->assertSame(0, bccomp('-2000', $this->balanceOf($this->accountByKey('salaries_payable')), 4));

        // The run shows it on the slip, display only — never in net, or
        // 2140 would carry the same money twice.
        $run = $this->approveRun('2026-01-15');
        $slip = $run->payslips()->sole();

        $this->assertSame(0, bccomp('2000', (string) $slip->bonuses_display_total, 4));
        $this->assertSame(0, bccomp('10000', (string) $slip->net, 4));
        $this->assertSame(0, bccomp('-12000', $this->balanceOf($this->accountByKey('salaries_payable')), 4));

        // Reversal restores, while nothing has paid it.
        app(EmployeeBonusPoster::class)->reverse($bonus, CarbonImmutable::parse('2026-01-31'));

        $this->assertSame(DocumentStatus::Void, $bonus->refresh()->status);
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByKey('bonuses_expense')), 4));
    }

    #[Test]
    public function the_ledger_screen_reverse_is_blocked_for_payroll_sources(): void
    {
        $this->assertTrue(SubledgerSourceTypes::contains(PayrollRun::class));
        $this->assertTrue(SubledgerSourceTypes::contains(EmployeeBonus::class));
        $this->assertTrue(SubledgerSourceTypes::contains(EmployeeAdvance::class));
        $this->assertTrue(SubledgerSourceTypes::contains(EmployeeAdvanceSettlement::class));
        $this->assertTrue(SubledgerSourceTypes::contains(EmployeePaymentVoucher::class));
    }

    #[Test]
    public function the_system_key_backfill_covers_existing_tenants(): void
    {
        // Simulate the pre-module tenant.
        DB::statement("UPDATE chart_of_accounts SET system_key = NULL, is_system = 0
            WHERE system_key IN ('salaries_payable', 'gosi_payable', 'salaries_expense',
                                 'employee_advances', 'employee_deductions_income',
                                 'direct_salaries_expense', 'gosi_expense', 'bonuses_expense')");
        DB::statement("DELETE FROM chart_of_accounts WHERE code IN ('1180', '4320', '5250', '5260', '5270')");

        $migration = require base_path('database/migrations/2026_09_02_100000_add_payroll_system_keys.php');
        $migration->up();

        $this->assertSame('salaries_payable', $this->accountByCode('2140')->system_key);
        $this->assertSame('gosi_payable', $this->accountByCode('2150')->system_key);
        $this->assertSame('salaries_expense', $this->accountByCode('5200')->system_key);

        $advances = $this->accountByCode('1180');
        $this->assertSame('employee_advances', $advances->system_key);
        $this->assertSame($this->accountByCode('1100')->getKey(), $advances->parent_id);
        $this->assertTrue($advances->is_postable);
        $this->assertNotNull($advances->path);

        $this->assertSame('employee_deductions_income', $this->accountByCode('4320')->system_key);
        $this->assertSame('direct_salaries_expense', $this->accountByCode('5250')->system_key);
        $this->assertSame('gosi_expense', $this->accountByCode('5260')->system_key);
        $this->assertSame('bonuses_expense', $this->accountByCode('5270')->system_key);
    }
}
