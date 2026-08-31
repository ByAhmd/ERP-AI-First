<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's share of a transfer.
 *
 * @property string $quantity
 * @property int $line_number
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'inventory_transfer_id', 'line_number',
    'product_id', 'quantity',
])]
class InventoryTransferItem extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * Number a line that arrives without one — the repeater defect's guard.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (blank($item->line_number)) {
                $item->line_number = 1 + (int) static::query()
                    ->where('inventory_transfer_id', $item->inventory_transfer_id)
                    ->max('line_number');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<InventoryTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
