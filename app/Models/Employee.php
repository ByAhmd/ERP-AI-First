<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeCostType;
use App\Enums\EmployeeStatus;
use App\Enums\NationalityStatus;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An employee — the payroll register's row.
 *
 * The salary-eligibility window is [first_salary_date, last_salary_date]:
 * a run pays the days where its period intersects that window. Termination
 * in this slice is the window closing plus the status; the end-of-service
 * arithmetic ships with the EOS slice.
 *
 * @property EmployeeStatus $status
 * @property EmployeeCostType $cost_type
 * @property NationalityStatus $nationality_status
 * @property CarbonImmutable $joined_on
 * @property CarbonImmutable $first_salary_date
 * @property ?CarbonImmutable $last_salary_date
 * @property ?CarbonImmutable $birth_date
 * @property bool $gosi_enrolled
 * @property ?string $company_id
 * @property ?string $branch_id
 */
#[Fillable([
    'company_id', 'reference', 'first_name', 'last_name', 'first_name_en',
    'last_name_en', 'gender', 'birth_date', 'email', 'phone', 'national_id',
    'iban', 'nationality_status', 'branch_id', 'department', 'job_title',
    'education_level', 'joined_on', 'cost_type', 'first_salary_date',
    'last_salary_date', 'salary_cycle', 'base_salary', 'gosi_enrolled',
    'gosi_wage', 'status',
])]
class Employee extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'salary_cycle' => 'monthly',
        'gosi_enrolled' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'cost_type' => EmployeeCostType::class,
            'nationality_status' => NationalityStatus::class,
            'birth_date' => 'date',
            'joined_on' => 'date',
            'first_salary_date' => 'date',
            'last_salary_date' => 'date',
            'gosi_enrolled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<EmployeeSalaryComponent, $this>
     */
    public function salaryComponents(): HasMany
    {
        return $this->hasMany(EmployeeSalaryComponent::class);
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return HasMany<EmployeeAdvance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function fullName(): string
    {
        if (app()->getLocale() === 'en' && filled($this->first_name_en)) {
            return trim($this->first_name_en.' '.($this->last_name_en ?? ''));
        }

        return trim($this->first_name.' '.$this->last_name);
    }

    public function isActive(): bool
    {
        return $this->status === EmployeeStatus::Active;
    }

    /**
     * Whether the eligibility window intersects [start, end].
     */
    public function eligibleBetween(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $windowStart = CarbonImmutable::instance($this->first_salary_date)->startOfDay();

        if ($windowStart->greaterThan($end)) {
            return false;
        }

        if ($this->last_salary_date !== null) {
            $windowEnd = CarbonImmutable::instance($this->last_salary_date)->startOfDay();

            if ($windowEnd->lessThan($start)) {
                return false;
            }
        }

        return true;
    }
}
