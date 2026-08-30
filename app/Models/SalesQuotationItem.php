<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\TaxCategory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a sales quotation.
 *
 * The same snapshot discipline as an invoice line, for the same reason: the
 * printed quotation must keep saying what it said after a product rename or a
 * tax re-rate. What differs is what happens to the snapshots later — an
 * invoice line's are final; a quotation line's rate and category are ignored
 * at conversion, which re-resolves them from `tax_id` at the invoice's date.
 *
 * @property DiscountType $discount_type
 * @property ?TaxCategory $tax_category
 * @property bool $is_inclusive
 * @property string $quantity
 * @property string $unit_price
 * @property string $tax_rate
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $line_total
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'sales_quotation_id', 'line_number',
    'product_id', 'product_name', 'product_description', 'unit_name',
    'quantity', 'unit_price', 'is_inclusive',
    'discount_value', 'discount_type', 'discount_amount',
    'tax_id', 'tax_rate', 'tax_category',
    'net_amount', 'tax_amount', 'line_total',
])]
class SalesQuotationItem extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_inclusive' => false,
        'discount_value' => 0,
        'discount_type' => 'percentage',
        'discount_amount' => 0,
        'tax_rate' => 0,
        'net_amount' => 0,
        'tax_amount' => 0,
        'line_total' => 0,
    ];

    /**
     * Number a line that arrives without one, and snapshot the product.
     *
     * The third table to need this exact hook — journal lines and invoice
     * items came first, each written by a repeater that never sets the line
     * number over a column with no database default. Duplicated rather than
     * shared for now: the credit-note item's hook must copy from the invoice
     * line before snapshotting the product, and a shared concern's listener
     * ordering would silently invert that. Extraction is its own refactor.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (blank($item->line_number)) {
                $item->line_number = 1 + (int) static::query()
                    ->where('sales_quotation_id', $item->sales_quotation_id)
                    ->max('line_number');
            }

            $item->copyProductSnapshot();
        });
    }

    /**
     * Take the name and unit from the product, once.
     */
    private function copyProductSnapshot(): void
    {
        if (filled($this->product_name) && filled($this->unit_name)) {
            return;
        }

        $product = $this->product_id === null ? null : Product::query()->find($this->product_id);

        if ($product === null) {
            // A free-text line is legitimate — a one-off charge with no
            // catalogue entry behind it — but it has to say something.
            $this->product_name = $this->product_name ?: ($this->product_description ?: '—');

            return;
        }

        $this->product_name = $this->product_name ?: $product->name;
        $this->unit_name = $this->unit_name ?: $product->unitType?->name;
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'is_inclusive' => 'boolean',
            'discount_type' => DiscountType::class,
            'tax_category' => TaxCategory::class,
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<SalesQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
