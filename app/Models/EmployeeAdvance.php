<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdvanceKind;
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
 * An employee advance — Qoyod's سلفة / مقدم راتب.
 *
 * Stores NO balance column: the remaining balance is the amount minus cash
 * settlements minus payroll recoveries, derived on read — the same
 * no-stored-mirror doctrine as the asset register.
 *
 * @property AdvanceKind $kind
 * @property DocumentStatus $status
 * @property CarbonImmutable $advance_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'employee_id', 'kind', 'amount',
    'advance_date', 'payment_account_id', 'notes', 'status',
    'journal_entry_id', 'reversal_journal_entry_id', 'approved_at',
    'approved_by_id', 'created_by_id',
])]
class EmployeeAdvance extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    private const SCALE = 4;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AdvanceKind::class,
            'status' => DocumentStatus::class,
            'advance_date' => 'date',
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
     * @return BelongsTo<Account, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<EmployeeAdvanceSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceSettlement::class);
    }

    /**
     * @return HasMany<PayslipAdvanceRecovery, $this>
     */
    public function recoveries(): HasMany
    {
        return $this->hasMany(PayslipAdvanceRecovery::class);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }

    /**
     * What is still owed back — derived, never stored.
     */
    public function remaining(): string
    {
        $settled = (string) ($this->settlements()->sum('amount') ?: '0');
        $recovered = (string) ($this->recoveries()->sum('amount') ?: '0');

        return bcsub(
            bcsub((string) $this->amount, $settled, self::SCALE),
            $recovered,
            self::SCALE,
        );
    }

    public function hasRepayments(): bool
    {
        return $this->settlements()->exists() || $this->recoveries()->exists();
    }
}
