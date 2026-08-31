<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\StockAdjustmentKind;
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
 * A stock adjustment — the manual door into the stock ledger.
 *
 * Opening balances and count variances behind one lifecycle: drafts are
 * edited, approved adjustments are immutable, and correction is a
 * counter-adjustment — never an edit of history.
 *
 * @property StockAdjustmentKind $kind
 * @property DocumentStatus $status
 * @property CarbonImmutable $adjustment_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'kind', 'status', 'branch_id',
    'adjustment_date', 'description', 'offset_account_id',
    'journal_entry_id', 'approved_at', 'approved_by_id', 'created_by_id',
])]
class StockAdjustment extends Model implements AuditableContract
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
            'kind' => StockAdjustmentKind::class,
            'status' => DocumentStatus::class,
            'adjustment_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockAdjustmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function offsetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'offset_account_id');
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
}
