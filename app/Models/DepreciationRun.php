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
 * A depreciation run — Qoyod's الإهلاك document.
 *
 * Born Approved: a run computes, posts and stamps in one transaction, so no
 * draft window ever exists for the ledger screen to edit. The only backward
 * step is the reversal action, which removes the charge rows and the ledger
 * money together and marks the run Void.
 *
 * @property DocumentStatus $status
 * @property CarbonImmutable $through_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'fixed_asset_type_id', 'fixed_asset_id',
    'through_period_id', 'through_date', 'status', 'journal_entry_id',
    'reversal_journal_entry_id', 'total_amount', 'assets_count',
    'created_by_id',
])]
class DepreciationRun extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'approved',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'through_date' => 'date',
        ];
    }

    /**
     * @return HasMany<DepreciationCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(DepreciationCharge::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<FixedAssetType, $this>
     */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(FixedAssetType::class, 'fixed_asset_type_id');
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function throughPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'through_period_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
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
