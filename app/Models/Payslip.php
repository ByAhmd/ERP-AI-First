<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeCostType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payslip — the payroll subledger's row, one per employee per period of
 * record.
 *
 * Every figure is stamped at approval, resolved to currency scale — the
 * record of what actually posted, never a live recomputation. The unique
 * (employee, period) index is the run-twice anchor: rows insert before the
 * run's entry posts, so a concurrent duplicate dies before money moves.
 *
 * @property EmployeeCostType $cost_type
 * @property ?string $company_id
 * @property ?string $branch_id
 */
#[Fillable([
    'company_id', 'payroll_run_id', 'employee_id', 'accounting_period_id',
    'branch_id', 'cost_type', 'base_salary', 'allowances_total',
    'bonuses_display_total', 'deductions_total', 'advance_recovery',
    'gosi_wage', 'gosi_employee', 'gosi_employer', 'gross', 'net',
    'journal_entry_id',
])]
class Payslip extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'cost_type' => EmployeeCostType::class,
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<PayslipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<PayslipAdvanceRecovery, $this>
     */
    public function advanceRecoveries(): HasMany
    {
        return $this->hasMany(PayslipAdvanceRecovery::class);
    }

    /**
     * @return HasMany<EmployeePaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(EmployeePaymentAllocation::class);
    }
}
