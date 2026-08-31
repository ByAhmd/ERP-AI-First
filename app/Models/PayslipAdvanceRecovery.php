<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which advance a payslip's recovery consumed, and by how much — recorded
 * oldest-first so per-advance balances stay exact, never prorated.
 *
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'payslip_id', 'employee_advance_id', 'amount',
])]
class PayslipAdvanceRecovery extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /**
     * @return BelongsTo<EmployeeAdvance, $this>
     */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }
}
