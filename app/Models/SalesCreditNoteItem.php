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
 * One line of a sales credit note.
 *
 * The rate and category are copied from the invoice line being credited and are
 * never re-resolved from the tax record. Crediting an invoice raised at 5% has
 * to return 5%, whatever the rate is today — and the invoice line already holds
 * the answer, which is exactly why it snapshots it.
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
    'company_id', 'sales_credit_note_id', 'line_number', 'sales_invoice_item_id',
    'product_id', 'product_name', 'product_description', 'unit_name',
    'quantity', 'unit_price', 'is_inclusive',
    'discount_value', 'discount_type', 'discount_amount',
    'tax_id', 'tax_rate', 'tax_category',
    'net_amount', 'tax_amount', 'line_total',
])]
class SalesCreditNoteItem extends Model
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
     * Number a line, and take everything the invoice line already decided.
     *
     * The repeater writes lines straight through the relationship and sets
     * neither, and neither column has a database default — the same defect the
     * invoice and the journal entry both hit. Done here so an import and an API
     * call produce the same row a clerk does.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (blank($item->line_number)) {
                $item->line_number = 1 + (int) static::query()
                    ->where('sales_credit_note_id', $item->sales_credit_note_id)
                    ->max('line_number');
            }

            $item->copyFromInvoiceLine();
            $item->copyProductSnapshot();
        });
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
     * @return BelongsTo<SalesCreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class, 'sales_credit_note_id');
    }

    /**
     * The invoice line this credits.
     *
     * @return BelongsTo<SalesInvoiceItem, $this>
     */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItem::class, 'sales_invoice_item_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Take the tax the invoice line was actually raised at.
     *
     * This is the whole reason a credit note cannot simply reuse the invoice's
     * recalculator: that one resolves the rate from the tax record, which is
     * right for a new invoice and wrong for a document correcting an old one.
     */
    private function copyFromInvoiceLine(): void
    {
        if ($this->sales_invoice_item_id === null) {
            return;
        }

        $line = SalesInvoiceItem::query()->find($this->sales_invoice_item_id);

        if ($line === null) {
            return;
        }

        $this->tax_id ??= $line->tax_id;
        $this->tax_rate = $line->tax_rate;
        $this->tax_category = $line->tax_category;
        $this->is_inclusive = $this->is_inclusive || $line->is_inclusive;
        $this->product_id ??= $line->product_id;
        $this->product_name = $this->product_name ?: $line->product_name;
        $this->unit_name = $this->unit_name ?: $line->unit_name;
    }

    private function copyProductSnapshot(): void
    {
        if (filled($this->product_name)) {
            return;
        }

        $product = $this->product_id === null ? null : Product::query()->find($this->product_id);

        $this->product_name = $product?->name ?: ($this->product_description ?: '—');
        $this->unit_name = $this->unit_name ?: $product?->unitType?->name;
    }
}
