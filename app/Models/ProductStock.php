<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's quantity at one branch.
 *
 * Quantity only: cost is company-wide on the product's cost row, so a
 * branch's stock value is its quantity times the company average — stated,
 * not stored. Rows are created lazily by the stock ledger while it holds
 * the product's cost-row lock, which is what makes creation race-free.
 *
 * @property string $quantity_on_hand
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'product_id', 'branch_id', 'quantity_on_hand',
])]
class ProductStock extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity_on_hand' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
