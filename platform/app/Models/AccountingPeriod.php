<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A period within a fiscal year — normally a month.
 *
 * Postings are accepted only when both the period and its year are open, so
 * closing a year seals every period inside it without touching each one.
 */
class AccountingPeriod extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'name',
        'sequence',
        'start_date',
        'end_date',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'sequence' => 'integer',
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
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

    /**
     * Both the period and its year must be open. A closed year overrides an
     * open period, never the other way round.
     */
    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings()
            && $this->fiscalYear->acceptsPostings();
    }
}
