<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetDisposalKind;
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
 * A disposal — Qoyod's استبعاد, by sale or by scrap.
 *
 * Draftable, then approved once: approval depreciates the asset to the
 * disposal date, clears the POSTED figures off the books and snapshots what
 * it removed. There is no un-dispose and no delete of an approved disposal —
 * no counter-document exists, and re-activating the asset would double-count
 * it.
 *
 * @property AssetDisposalKind $kind
 * @property DocumentStatus $status
 * @property CarbonImmutable $disposal_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'kind', 'status', 'fixed_asset_id',
    'disposal_date', 'proceeds', 'tax_id', 'tax_amount',
    'proceeds_account_id', 'gain_loss_amount', 'cost_removed',
    'accumulated_removed', 'catchup_run_id', 'journal_entry_id', 'notes',
    'approved_at', 'approved_by_id', 'created_by_id',
])]
class FixedAssetDisposal extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'tax_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => AssetDisposalKind::class,
            'status' => DocumentStatus::class,
            'disposal_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function proceedsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'proceeds_account_id');
    }

    /**
     * @return BelongsTo<DepreciationRun, $this>
     */
    public function catchupRun(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'catchup_run_id');
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
