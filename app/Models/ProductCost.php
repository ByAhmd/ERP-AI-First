<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tracked product's company-wide stock position — THE lock row.
 *
 * `total_value` is authoritative and `average_cost` a derived mirror:
 * value divided by quantity when quantity is positive, zero otherwise —
 * never divide at zero. Every stock mutation locks this row first; nothing
 * reads the average outside that lock to produce a posted figure.
 *
 * @property string $quantity_on_hand
 * @property string $total_value
 * @property string $average_cost
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'product_id', 'quantity_on_hand', 'total_value', 'average_cost',
])]
class ProductCost extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity_on_hand' => 0,
        'total_value' => 0,
        'average_cost' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'total_value' => 'decimal:4',
            'average_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
