<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A payroll run — Qoyod's مسير الرواتب.
 *
 * Keyed to an accounting period, never a free date: the accrual is dated
 * the period's last day at approval, so a run cannot silently misstate a
 * month. Uniqueness lives per employee-period on the payslips — Qoyod's
 * own rule, which permits a supplementary run for missed employees.
 *
 * @property DocumentStatus $status
 * @property ?CarbonImmutable $run_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'accounting_period_id', 'run_date', 'status',
    'journal_entry_id', 'reversal_journal_entry_id', 'gross_total',
    'allowances_total', 'deductions_total', 'advance_recovery_total',
    'gosi_employee_total', 'gosi_employer_total', 'net_total',
    'employees_count', 'notes', 'approved_at', 'approved_by_id',
    'created_by_id',
])]
class PayrollRun extends Model implements AuditableContract
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
            'status' => DocumentStatus::class,
            'run_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return HasMany<PayrollRunExclusion, $this>
     */
    public function exclusions(): HasMany
    {
        return $this->hasMany(PayrollRunExclusion::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }

    public function isVoid(): bool
    {
        return $this->status === DocumentStatus::Void;
    }
}
