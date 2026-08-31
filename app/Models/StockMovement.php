<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement in the stock ledger — append-only, never edited.
 *
 * The integer primary key is the application order: cost applies
 * running-forward in posting sequence, while `movement_date` carries the
 * document's date, and a backdated document is diagnosable precisely
 * because the two orders are both stored. The unit cost and value are
 * snapshots of what was applied — never re-derived from the product.
 *
 * @property int $id
 * @property CarbonImmutable $movement_date
 * @property string $quantity
 * @property string $unit_cost
 * @property string $value
 * @property string $branch_qty_after
 * @property string $qty_after
 * @property string $value_after
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'product_id', 'branch_id', 'movement_date',
    'source_type', 'source_id', 'journal_entry_id',
    'quantity', 'unit_cost', 'value',
    'branch_qty_after', 'qty_after', 'value_after',
])]
class StockMovement extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:4',
            'branch_qty_after' => 'decimal:4',
            'qty_after' => 'decimal:4',
            'value_after' => 'decimal:4',
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

    /**
     * The document this movement came from.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
