<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Enums\DocumentStatus;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Models\EmployeePaymentAllocation;
use App\Models\EmployeePaymentVoucher;
use App\Models\Payslip;
use App\Services\Payroll\EmployeeBonusPoster;
use App\Services\Payroll\EmployeePaymentPoster;
use App\Services\Payroll\Exceptions\PayrollRunRejected;
use App\Services\Payroll\Exceptions\VoucherRejected;
use App\Services\Payroll\PayrollRunEngine;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Employee payment vouchers: every riyal named, or nothing moves.
 */
final class VoucherTest extends PayrollTestCase
{
    private Employee $employee;

    private Payslip $slip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = $this->makeEmployee();

        $this->approveRun('2026-01-15');

        $this->slip = Payslip::query()->sole();
    }

    /**
     * @param  list<array{payslip?: string, bonus?: string, amount: string}>  $targets
     */
    private function makeVoucher(string $amount, array $targets, ?Employee $employee = null): EmployeePaymentVoucher
    {
        $voucher = EmployeePaymentVoucher::create([
            'reference' => app(EmployeePaymentPoster::class)->nextReference(),
            'employee_id' => ($employee ?? $this->employee)->getKey(),
            'amount' => $amount,
            'payment_date' => '2026-02-01',
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
        ]);

        foreach ($targets as $target) {
            EmployeePaymentAllocation::create([
                'employee_payment_voucher_id' => $voucher->getKey(),
                'payslip_id' => $target['payslip'] ?? null,
                'employee_bonus_id' => $target['bonus'] ?? null,
                'amount' => $target['amount'],
            ]);
        }

        return $voucher;
    }

    #[Test]
    public function a_voucher_pays_the_payable_down_and_reverses_whole(): void
    {
        $voucher = $this->makeVoucher('6000', [
            ['payslip' => $this->slip->getKey(), 'amount' => '6000'],
        ]);

        app(EmployeePaymentPoster::class)->approve($voucher);

        // 10,000 accrued − 6,000 paid.
        $this->assertSame(0, bccomp('-4000', $this->balanceOf($this->accountByKey('salaries_payable')), 4));

        // The paid run refuses reversal until the money is unwound.
        try {
            app(PayrollRunEngine::class)->reverse(
                $this->slip->run()->firstOrFail(),
                CarbonImmutable::parse('2026-02-05'),
            );
            $this->fail('A paid run was reversed.');
        } catch (PayrollRunRejected) {
        }

        app(EmployeePaymentPoster::class)->reverse($voucher, CarbonImmutable::parse('2026-02-10'));

        $this->assertSame(DocumentStatus::Void, $voucher->refresh()->status);
        $this->assertSame(0, EmployeePaymentAllocation::query()->count());
        $this->assertSame(0, bccomp('-10000', $this->balanceOf($this->accountByKey('salaries_payable')), 4));

        // With the money unwound, the run may reverse.
        app(PayrollRunEngine::class)->reverse(
            $this->slip->run()->firstOrFail(),
            CarbonImmutable::parse('2026-02-15'),
        );

        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByKey('salaries_payable')), 4));
    }

    #[Test]
    public function a_voucher_settles_a_bonus_directly(): void
    {
        $bonus = EmployeeBonus::create([
            'reference' => app(EmployeeBonusPoster::class)->nextReference(),
            'employee_id' => $this->employee->getKey(),
            'kind' => 'commission',
            'amount' => '1500',
            'bonus_date' => '2026-01-25',
        ]);

        app(EmployeeBonusPoster::class)->approve($bonus);

        $voucher = $this->makeVoucher('1500', [
            ['bonus' => $bonus->getKey(), 'amount' => '1500'],
        ]);

        app(EmployeePaymentPoster::class)->approve($voucher);

        // 10,000 net + 1,500 bonus − 1,500 paid.
        $this->assertSame(0, bccomp('-10000', $this->balanceOf($this->accountByKey('salaries_payable')), 4));

        // The paid bonus refuses its own reversal now.
        $this->expectException(\App\Services\Payroll\Exceptions\PayrollRuleViolation::class);

        app(EmployeeBonusPoster::class)->reverse($bonus, CarbonImmutable::parse('2026-02-15'));
    }

    #[Test]
    public function voucher_guards_hold(): void
    {
        // Over-allocation beyond the slip's outstanding.
        $over = $this->makeVoucher('12000', [
            ['payslip' => $this->slip->getKey(), 'amount' => '12000'],
        ]);

        try {
            app(EmployeePaymentPoster::class)->approve($over);
            $this->fail('An allocation exceeded the outstanding.');
        } catch (VoucherRejected) {
        }

        // A voucher not fully allocated is refused.
        $partial = $this->makeVoucher('1000', [
            ['payslip' => $this->slip->getKey(), 'amount' => '500'],
        ]);

        try {
            app(EmployeePaymentPoster::class)->approve($partial);
            $this->fail('A half-named voucher was approved.');
        } catch (VoucherRejected) {
        }

        // Another employee's slip is not reachable.
        $other = $this->makeEmployee(['base_salary' => '2000']);

        $cross = $this->makeVoucher('1000', [
            ['payslip' => $this->slip->getKey(), 'amount' => '1000'],
        ], $other);

        try {
            app(EmployeePaymentPoster::class)->approve($cross);
            $this->fail('A cross-employee allocation was approved.');
        } catch (VoucherRejected) {
        }

        // Nothing moved through any of it.
        $this->assertSame(0, bccomp('-10000', $this->balanceOf($this->accountByKey('salaries_payable')), 4));
        $this->assertSame(0, EmployeePaymentVoucher::query()->where('status', DocumentStatus::Approved->value)->count());
    }
}
