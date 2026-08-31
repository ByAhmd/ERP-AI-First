<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee left out of a draft run — the draft's only stored selection
 * state; eligibility itself is recomputed at approval.
 *
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'payroll_run_id', 'employee_id',
])]
class PayrollRunExclusion extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
