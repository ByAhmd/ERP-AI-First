<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeductionKind;
use App\Enums\DocumentStatus;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A one-off deduction — Qoyod's خصم, posted by the run that consumes it.
 *
 * No entry of its own: the run credits the income account and `payslip_id`
 * marks the consumption. A run reversal clears the mark, and an unconsumed
 * approved deduction rolls forward to the next run — never double-counted,
 * never silently dropped.
 *
 * @property DeductionKind $kind
 * @property DocumentStatus $status
 * @property CarbonImmutable $deduction_date
 * @property ?string $company_id
 * @property ?string $payslip_id
 */
#[Fillable([
    'company_id', 'reference', 'employee_id', 'kind', 'amount',
    'deduction_date', 'description', 'status', 'payslip_id',
    'created_by_id',
])]
class EmployeeDeduction extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DeductionKind::class,
            'status' => DocumentStatus::class,
            'deduction_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Payslip, $this>
     */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }

    public function isConsumed(): bool
    {
        return $this->payslip_id !== null;
    }
}
