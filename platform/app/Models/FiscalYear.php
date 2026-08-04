<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodStatus;
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
 * A financial year and the window in which its figures may still change.
 *
 * @property PeriodStatus $status
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property ?CarbonImmutable $closed_at
 * @property ?string $closed_by_id
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'name', 'start_date', 'end_date', 'status',
])]
class FiscalYear extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AccountingPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function containsDate(\DateTimeInterface $date): bool
    {
        return $date >= $this->start_date->startOfDay()
            && $date <= $this->end_date->endOfDay();
    }

    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings();
    }
}
