<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BonusKind;
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
 * A bonus — Qoyod's مكافأة, its own document with its own accrual.
 *
 * Approval posts DR bonuses expense / CR salaries payable, which is why
 * the payroll run must NEVER add bonuses into net: they already stand on
 * 2140 and a voucher settles them directly.
 *
 * @property BonusKind $kind
 * @property DocumentStatus $status
 * @property CarbonImmutable $bonus_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'employee_id', 'kind', 'amount',
    'bonus_date', 'notes', 'status', 'journal_entry_id',
    'reversal_journal_entry_id', 'approved_at', 'approved_by_id',
    'created_by_id',
])]
class EmployeeBonus extends Model implements AuditableContract
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
            'kind' => BonusKind::class,
            'status' => DocumentStatus::class,
            'bonus_date' => 'date',
            'approved_at' => 'datetime',
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
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<EmployeePaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(EmployeePaymentAllocation::class);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }
}
