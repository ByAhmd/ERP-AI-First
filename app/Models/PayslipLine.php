<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One display line of a payslip: base, an allowance, a bonus, a deduction,
 * an advance recovery or the employee's GOSI share — the slip's story,
 * labelled at approval.
 *
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'payslip_id', 'kind', 'salary_component_id',
    'source_type', 'source_id', 'label', 'amount',
])]
class PayslipLine extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
