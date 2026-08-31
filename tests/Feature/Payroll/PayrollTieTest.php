<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAdvance;
use App\Models\EmployeeBonus;
use App\Models\EmployeeDeduction;
use App\Models\EmployeePaymentAllocation;
use App\Models\EmployeePaymentVoucher;
use App\Models\Payslip;
use App\Services\Payroll\EmployeeAdvancePoster;
use App\Services\Payroll\EmployeeBonusPoster;
use App\Services\Payroll\EmployeePaymentPoster;
use App\Services\Payroll\PayrollRunEngine;
use App\Services\Payroll\Reports\PayrollTie;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * The closing test: the payable and the advances tie to their subledgers
 * through the whole life the slice supports.
 */
final class PayrollTieTest extends PayrollTestCase
{
    private function assertTied(string $stage): void
    {
        $tie = app(PayrollTie::class)->build();

        $this->assertTrue($tie['balanced'], "The payroll tie broke at: {$stage}.");

        foreach ($tie['rows'] as $row) {
            if ($row['informational']) {
                continue;
            }

            $this->assertSame(
                0,
                bccomp('0', $row['difference'], 4),
                "Account {$row['account']->code} ({$row['role']}) is off by {$row['difference']} at: {$stage}.",
            );
        }
    }

    #[Test]
    public function the_payroll_subledgers_tie_to_the_control_accounts(): void
    {
        $second = \App\Models\Branch::create(['code' => 'B2', 'name' => 'فرع جدة']);

        $housing = $this->makeComponent('بدل سكن', gosi: true, accountCode: '5300');

        $saudi = $this->makeEmployee(['gosi_enrolled' => true]);
        $this->assign($saudi, $housing, '2000');

        $foreign = $this->makeEmployee([
            'nationality_status' => 'non_saudi',
            'cost_type' => 'direct',
            'branch_id' => $second->getKey(),
            'base_salary' => '7000',
            'gosi_enrolled' => true,
        ]);

        // An advance, a bonus and a deduction in the mix.
        $advance = EmployeeAdvance::create([
            'reference' => app(EmployeeAdvancePoster::class)->nextReference(),
            'employee_id' => $foreign->getKey(),
            'kind' => 'salary_advance',
            'amount' => '1200',
            'advance_date' => '2026-01-05',
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
        ]);

        app(EmployeeAdvancePoster::class)->approve($advance);
        $this->assertTied('advance issued');

        $bonus = EmployeeBonus::create([
            'reference' => app(EmployeeBonusPoster::class)->nextReference(),
            'employee_id' => $saudi->getKey(),
            'kind' => 'grant',
            'amount' => '800',
            'bonus_date' => '2026-01-20',
        ]);

        app(EmployeeBonusPoster::class)->approve($bonus);
        $this->assertTied('bonus approved');

        EmployeeDeduction::create([
            'reference' => 'DED-00001',
            'employee_id' => $saudi->getKey(),
            'kind' => 'violation',
            'amount' => '250',
            'deduction_date' => '2026-01-12',
            'status' => \App\Enums\DocumentStatus::Approved,
        ]);

        // The run itself.
        $run = $this->approveRun('2026-01-15');
        $this->assertTied('run approved');

        // A partial voucher on the Saudi's slip, plus the bonus in full.
        $slip = Payslip::query()->where('employee_id', $saudi->getKey())->sole();

        $voucher = EmployeePaymentVoucher::create([
            'reference' => app(EmployeePaymentPoster::class)->nextReference(),
            'employee_id' => $saudi->getKey(),
            'amount' => '5800',
            'payment_date' => '2026-02-01',
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
        ]);

        EmployeePaymentAllocation::create([
            'employee_payment_voucher_id' => $voucher->getKey(),
            'payslip_id' => $slip->getKey(),
            'amount' => '5000',
        ]);

        EmployeePaymentAllocation::create([
            'employee_payment_voucher_id' => $voucher->getKey(),
            'employee_bonus_id' => $bonus->getKey(),
            'amount' => '800',
        ]);

        app(EmployeePaymentPoster::class)->approve($voucher);
        $this->assertTied('voucher paid');

        // A second voucher clears the foreigner's slip entirely.
        $foreignSlip = Payslip::query()->where('employee_id', $foreign->getKey())->sole();

        $second = EmployeePaymentVoucher::create([
            'reference' => app(EmployeePaymentPoster::class)->nextReference(),
            'employee_id' => $foreign->getKey(),
            'amount' => (string) $foreignSlip->net,
            'payment_date' => '2026-02-02',
            'payment_account_id' => $this->accountByCode('1110')->getKey(),
        ]);

        EmployeePaymentAllocation::create([
            'employee_payment_voucher_id' => $second->getKey(),
            'payslip_id' => $foreignSlip->getKey(),
            'amount' => (string) $foreignSlip->net,
        ]);

        app(EmployeePaymentPoster::class)->approve($second);
        $this->assertTied('second voucher paid');

        // Unwind the vouchers, reverse the run, re-run — still tied at
        // every step.
        app(EmployeePaymentPoster::class)->reverse($voucher, CarbonImmutable::parse('2026-02-10'));
        app(EmployeePaymentPoster::class)->reverse($second, CarbonImmutable::parse('2026-02-10'));
        $this->assertTied('vouchers reversed');

        app(PayrollRunEngine::class)->reverse($run, CarbonImmutable::parse('2026-02-15'));
        $this->assertTied('run reversed');

        $this->approveRun('2026-01-15');
        $this->assertTied('re-run');

        // And the ledger as a whole still stands.
        $this->assertSame(2, Payslip::query()->count());
    }
}
