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
 * One line of a purchase invoice.
 *
 * The sales line's snapshot discipline plus one buy-side column: the expense
 * account the line debits, copied from the product at entry and editable.
 * The copy, not the product's current pointer, is what posts — re-pointing a
 * product later must not restate bills already approved.
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
 * @property ?string $expense_account_id
 */
#[Fillable([
    'company_id', 'purchase_invoice_id', 'line_number',
    'product_id', 'product_name', 'product_description', 'unit_name',
    'is_stocked',
    'expense_account_id',
    'quantity', 'unit_price', 'is_inclusive',
    'discount_value', 'discount_type', 'discount_amount',
    'tax_id', 'tax_rate', 'tax_category',
    'net_amount', 'tax_amount', 'line_total',
])]
class PurchaseInvoiceItem extends Model
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
     * Number a line that arrives without one, and snapshot the product.
     *
     * The fifth table to need this exact hook — the model comment on the
     * sales invoice item keeps the tally. The buy-side addition: the expense
     * account also falls back to the product's, so an import that omits it
     * lands where the product says instead of failing the NOT NULL — but a
     * product-less line with no account still fails loudly, which is right.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (blank($item->line_number)) {
                $item->line_number = 1 + (int) static::query()
                    ->where('purchase_invoice_id', $item->purchase_invoice_id)
                    ->max('line_number');
            }

            $item->copyProductSnapshot();
        });
    }

    /**
     * Take the name, unit and expense account from the product, once.
     */
    private function copyProductSnapshot(): void
    {
        $product = $this->product_id === null ? null : Product::query()->find($this->product_id);

        if ($product !== null && blank($this->expense_account_id)) {
            $this->expense_account_id = $product->expense_account_id;
        }

        if (filled($this->product_name) && filled($this->unit_name)) {
            return;
        }

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
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
