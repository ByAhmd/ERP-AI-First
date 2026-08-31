<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a stock adjustment.
 *
 * The signed delta a count found, or an opening quantity. The entered
 * unit cost matters only on increases; a decrease relieves at the running
 * average resolved at approval, snapshotted into `resolved_unit_cost` as
 * the record of what was applied.
 *
 * @property string $quantity_change
 * @property ?string $unit_cost
 * @property ?string $resolved_unit_cost
 * @property ?string $value_change
 * @property int $line_number
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'stock_adjustment_id', 'line_number',
    'product_id', 'quantity_change', 'unit_cost',
    'resolved_unit_cost', 'value_change',
])]
class StockAdjustmentItem extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * Number a line that arrives without one — the repeater defect's guard,
     * in the model where every path reaches it.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (blank($item->line_number)) {
                $item->line_number = 1 + (int) static::query()
                    ->where('stock_adjustment_id', $item->stock_adjustment_id)
                    ->max('line_number');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity_change' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'resolved_unit_cost' => 'decimal:4',
            'value_change' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
