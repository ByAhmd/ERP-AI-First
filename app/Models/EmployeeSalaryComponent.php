<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A component assigned to an employee, with its amount — a fixed figure or
 * a percent, per the component's calculation.
 *
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'employee_id', 'salary_component_id', 'amount',
])]
class EmployeeSalaryComponent extends Model
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

    /**
     * @return BelongsTo<SalaryComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
