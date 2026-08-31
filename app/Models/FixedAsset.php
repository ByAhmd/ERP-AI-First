<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetAcquisitionKind;
use App\Enums\FixedAssetStatus;
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
 * A registered asset — one row in the register, Qoyod's أصل مسجل.
 *
 * Deliberately stores NO accumulated or book-value figure: accumulated to
 * date is the opening figure plus the sum of posted charge rows, derived on
 * read. A stored mirror is exactly the kind of number that drifts from the
 * ledger the first time a reversal touches one side and not the other.
 *
 * Once any charge or disposal exists, only the descriptive fields stay
 * editable; the financial ones are frozen — prospective re-life ships with
 * the additions slice.
 *
 * @property FixedAssetStatus $status
 * @property AssetAcquisitionKind $acquisition_kind
 * @property CarbonImmutable $acquisition_date
 * @property CarbonImmutable $in_service_date
 * @property ?CarbonImmutable $opening_depreciated_through
 * @property bool $is_depreciable
 * @property ?int $useful_life_months
 * @property ?string $company_id
 * @property ?string $branch_id
 */
#[Fillable([
    'company_id', 'fixed_asset_type_id', 'reference', 'name', 'name_en',
    'description', 'serial_number', 'barcode', 'branch_id', 'status',
    'acquisition_kind', 'acquisition_date', 'in_service_date', 'cost',
    'salvage_value', 'useful_life_months', 'is_depreciable',
    'opening_accumulated_depreciation', 'opening_depreciated_through',
    'acquisition_journal_entry_id', 'purchase_invoice_item_id',
])]
class FixedAsset extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    private const SCALE = 4;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'salvage_value' => 0,
        'opening_accumulated_depreciation' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => FixedAssetStatus::class,
            'acquisition_kind' => AssetAcquisitionKind::class,
            'acquisition_date' => 'date',
            'in_service_date' => 'date',
            'opening_depreciated_through' => 'date',
            'is_depreciable' => 'boolean',
            'useful_life_months' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FixedAssetType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(FixedAssetType::class, 'fixed_asset_type_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<DepreciationCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(DepreciationCharge::class)->orderBy('id');
    }

    /**
     * @return HasMany<FixedAssetDisposal, $this>
     */
    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function acquisitionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'acquisition_journal_entry_id');
    }

    public function isActive(): bool
    {
        return $this->status === FixedAssetStatus::Active;
    }

    /**
     * Accumulated depreciation to date — the opening figure plus every
     * posted charge, read from the subledger, never stored.
     */
    public function accumulatedDepreciation(): string
    {
        $charges = (string) ($this->charges()->sum('amount') ?: '0');

        return bcadd((string) $this->opening_accumulated_depreciation, $charges, self::SCALE);
    }

    public function bookValue(): string
    {
        return bcsub((string) $this->cost, $this->accumulatedDepreciation(), self::SCALE);
    }

    /**
     * What straight-line depreciation still has to consume.
     */
    public function remainingDepreciableBase(): string
    {
        $remaining = bcsub(
            bcsub((string) $this->cost, (string) $this->salvage_value, self::SCALE),
            $this->accumulatedDepreciation(),
            self::SCALE,
        );

        return bccomp($remaining, '0', self::SCALE) > 0 ? $remaining : '0.0000';
    }

    /**
     * Whether the financial fields may still be edited.
     *
     * A posted acquisition locks too: the entry snapshotted the cost, and
     * editing the register's copy would break the tie by exactly the edit.
     */
    public function isFinanciallyLocked(): bool
    {
        return $this->acquisition_journal_entry_id !== null
            || $this->charges()->exists()
            || $this->disposals()->exists();
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? (string) $this->name_en
            : (string) $this->name;
    }
}
