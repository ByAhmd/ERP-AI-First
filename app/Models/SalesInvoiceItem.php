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
 * One line of a sales invoice.
 *
 * Everything needed to explain the line is stored on it — the name billed, the
 * unit, the price, whether that price included tax, the discount, the rate and
 * the category. Nothing is read back through a relationship to produce a
 * figure. A product can be renamed and a tax re-rated; the invoice must keep
 * saying what it said when it was issued.
 *
 * @property DiscountType $discount_type
 * @property ?TaxCategory $tax_category
 * @property bool $is_inclusive
 * @property bool $is_stocked
 * @property string $quantity
 * @property string $unit_price
 * @property string $tax_rate
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $line_total
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'sales_invoice_id', 'line_number',
    'product_id', 'product_name', 'product_description', 'unit_name',
    'is_stocked',
    'quantity', 'unit_price', 'is_inclusive',
    'discount_value', 'discount_type', 'discount_amount',
    'tax_id', 'tax_rate', 'tax_category',
    'net_amount', 'tax_amount', 'line_total',
])]
class SalesInvoiceItem extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_inclusive' => false,
        'is_stocked' => false,
        'discount_value' => 0,
        'discount_type' => 'percentage',
        'discount_amount' => 0,
        'tax_rate' => 0,
        'net_amount' => 0,
        'tax_amount' => 0,
        'line_total' => 0,
    ];

    /**
     * Number a line that arrives without one.
     *
     * The Filament repeater writes lines straight through the relationship, so
     * it never sets this — and the column has no database default, so the
     * insert fails outright. The recalculator renumbers the whole invoice
     * afterwards, which is too late to help.
     *
     * This is the second time this exact defect appeared — the journal entry
     * form hit it first — and the quotation item is now the third, on
     * schedule. Any table whose rows are
     * written by a repeater and numbered by a service needs the fallback here,
     * in the model, where every path reaches it.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (blank($item->line_number)) {
                $item->line_number = 1 + (int) static::query()
                    ->where('sales_invoice_id', $item->sales_invoice_id)
                    ->max('line_number');
            }

            $item->copyProductSnapshot();
        });
    }

    /**
     * Take the name and unit from the product, once.
     *
     * The line has to keep saying what was billed after the product is renamed,
     * so these are copies rather than a relationship read at display time. Done
     * here rather than in the form because an import and an API call have to
     * produce the same row a clerk does.
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
            'is_stocked' => 'boolean',
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
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
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
