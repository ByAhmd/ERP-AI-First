<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A classification of fixed assets — Qoyod's تصنيف الأصول.
 *
 * Carries the three accounts every posting for its assets resolves through.
 * The keyed system accounts are only this table's form defaults: a company
 * wanting per-class accounts creates children in the chart and points a type
 * at them.
 *
 * The accounts and the depreciable flag lock once any asset of the type has
 * a posted charge or a disposal — changing them then would strand posted
 * balances on the old accounts.
 *
 * @property bool $is_depreciable
 * @property ?int $default_useful_life_months
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'name', 'name_en', 'description',
    'asset_account_id', 'accumulated_depreciation_account_id',
    'depreciation_expense_account_id',
    'default_useful_life_months', 'is_depreciable',
])]
class FixedAssetType extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_depreciable' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_depreciable' => 'boolean',
            'default_useful_life_months' => 'integer',
        ];
    }

    /**
     * @return HasMany<FixedAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? (string) $this->name_en
            : (string) $this->name;
    }

    /**
     * Whether the account fields and the depreciable flag may still change.
     */
    public function isStructureLocked(): bool
    {
        return DepreciationCharge::query()
            ->whereIn('fixed_asset_id', $this->assets()->select('id'))
            ->exists()
            || FixedAssetDisposal::query()
                ->whereIn('fixed_asset_id', $this->assets()->select('id'))
                ->exists();
    }
}
