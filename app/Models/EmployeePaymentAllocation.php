<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One voucher's application to one payslip OR one bonus — exactly one
 * target set, guarded by the poster.
 *
 * @property ?string $company_id
 * @property ?string $payslip_id
 * @property ?string $employee_bonus_id
 */
#[Fillable([
    'company_id', 'employee_payment_voucher_id', 'payslip_id',
    'employee_bonus_id', 'amount',
])]
class EmployeePaymentAllocation extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @return BelongsTo<EmployeePaymentVoucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(EmployeePaymentVoucher::class, 'employee_payment_voucher_id');
    }

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /**
     * @return BelongsTo<EmployeeBonus, $this>
     */
    public function bonus(): BelongsTo
    {
        return $this->belongsTo(EmployeeBonus::class, 'employee_bonus_id');
    }
}
