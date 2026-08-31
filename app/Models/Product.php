<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Something a company sells or buys.
 *
 * Audited: the description and unit printed on a tax invoice are part of what
 * ZATCA checks it against, so changing them later has to leave a trail showing
 * what they were when the invoice was raised.
 *
 * A price here is a default the document copies, never a value the document
 * reads back. An invoice raised in March must keep reporting March's price
 * after someone re-prices the catalogue in April.
 *
 * @property ProductType $type
 * @property bool $track_inventory
 * @property bool $is_sold
 * @property bool $is_purchased
 * @property bool $is_active
 * @property ?string $selling_price
 * @property ?string $buying_price
 * @property ?string $company_id
 *
 * @see ProductObserver
 */
#[Fillable([
    'company_id', 'type', 'track_inventory', 'name', 'name_en', 'sku', 'barcode',
    'category_id', 'unit_type_id', 'tax_id', 'expense_account_id', 'description',
    'terms_and_conditions', 'is_sold', 'is_purchased',
    'selling_price', 'buying_price', 'is_active',
])]
class Product extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'product',
        'is_sold' => true,
        'is_purchased' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'track_inventory' => 'boolean',
            'is_sold' => 'boolean',
            'is_purchased' => 'boolean',
            'is_active' => 'boolean',
            'selling_price' => 'decimal:4',
            'buying_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<ProductUnitType, $this>
     */
    public function unitType(): BelongsTo
    {
        return $this->belongsTo(ProductUnitType::class, 'unit_type_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    /**
     * Where buying this product lands in the ledger.
     *
     * A default the bill line copies and may override — the line's own
     * snapshot is what posts, so re-pointing the product later must not
     * restate bills already approved.
     *
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /**
     * Products a sales document may put on a line.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_sold', true)->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_purchased', true)->where('is_active', true);
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en
            : $this->name;
    }

    /**
     * Whether invoicing this should also relieve stock.
     *
     * Both halves are required: a stockable type AND the tracking flag. The
     * flag defaults off, so every product from before the inventory slice
     * keeps posting exactly as it did — flipping the whole catalogue to
     * tracked at quantity zero would sell everything at a cost of nothing.
     */
    public function isStocked(): bool
    {
        return $this->track_inventory && $this->type->isStocked();
    }

    /**
     * @return HasOne<ProductCost, $this>
     */
    public function costRecord(): HasOne
    {
        return $this->hasOne(ProductCost::class);
    }

    /**
     * @return HasMany<ProductStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('id');
    }
}
