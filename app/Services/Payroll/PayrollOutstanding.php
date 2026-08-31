<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\DocumentStatus;
use App\Models\EmployeeBonus;
use App\Models\EmployeePaymentAllocation;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is still owed on a payslip or a bonus — ONE definition, shared by
 * the voucher poster and every screen that shows an open balance.
 *
 * Only APPROVED vouchers count: a draft's allocation rows are working
 * material, and counting them would make a voucher refuse itself at
 * approval for the money it is about to pay.
 */
final class PayrollOutstanding
{
    private const SCALE = 4;

    public function payslipOutstanding(Payslip $payslip): string
    {
        $allocated = (string) (EmployeePaymentAllocation::query()
            ->where('payslip_id', $payslip->getKey())
            ->whereHas('voucher', fn (Builder $q) => $q->where('status', DocumentStatus::Approved))
            ->sum('amount') ?: '0');

        return bcsub((string) $payslip->net, $allocated, self::SCALE);
    }

    public function bonusOutstanding(EmployeeBonus $bonus): string
    {
        $allocated = (string) (EmployeePaymentAllocation::query()
            ->where('employee_bonus_id', $bonus->getKey())
            ->whereHas('voucher', fn (Builder $q) => $q->where('status', DocumentStatus::Approved))
            ->sum('amount') ?: '0');

        return bcsub((string) $bonus->amount, $allocated, self::SCALE);
    }
}
