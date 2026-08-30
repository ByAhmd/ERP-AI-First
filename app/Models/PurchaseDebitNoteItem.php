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
 * One line of a purchase debit note.
 *
 * The rate, category and expense account are copied from the bill line being
 * corrected and never re-resolved. Returning goods billed at 5% has to hand
 * back 5%, whatever the rate is today — and the correction must relieve the
 * account the cost actually landed in, not whatever the product points at
 * now.
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
 * @property ?string $expense_account_id
 */
#[Fillable([
    'company_id', 'purchase_debit_note_id', 'line_number', 'purchase_invoice_item_id',
    'product_id', 'product_name', 'product_description', 'unit_name',
    'expense_account_id',
    'quantity', 'unit_price', 'is_inclusive',
    'discount_value', 'discount_type', 'discount_amount',
    'tax_id', 'tax_rate', 'tax_category',
    'net_amount', 'tax_amount', 'line_total',
])]
class PurchaseDebitNoteItem extends Model
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
     * Number a line, and take everything the bill line already decided.
     *
     * The ordering matters and is why this hook stays duplicated rather than
     * shared: the bill-line copy must run before the product snapshot, or a
     * renamed product would overwrite what was actually billed.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $item): void {
            if (blank($item->line_number)) {
                $item->line_number = 1 + (int) static::query()
                    ->where('purchase_debit_note_id', $item->purchase_debit_note_id)
                    ->max('line_number');
            }

            $item->copyFromBillLine();
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
     * @return BelongsTo<PurchaseDebitNote, $this>
     */
    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(PurchaseDebitNote::class, 'purchase_debit_note_id');
    }

    /**
     * The bill line this corrects.
     *
     * @return BelongsTo<PurchaseInvoiceItem, $this>
     */
    public function billItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceItem::class, 'purchase_invoice_item_id');
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
     * Take the tax and the expense account the bill line actually carried.
     */
    private function copyFromBillLine(): void
    {
        $this->resolveBillLine();

        if ($this->purchase_invoice_item_id === null) {
            return;
        }

        $line = PurchaseInvoiceItem::query()->find($this->purchase_invoice_item_id);

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

        if (blank($this->expense_account_id)) {
            $this->expense_account_id = $line->expense_account_id;
        }
    }

    /**
     * Find the bill line this corrects, when the form did not say.
     *
     * Matched on the product against the parent bill — the same constrained
     * guess the sales credit note makes, for the same reason: a guess that
     * anchors the correction to a real line beats no anchor at all.
     */
    private function resolveBillLine(): void
    {
        if ($this->purchase_invoice_item_id !== null || $this->product_id === null) {
            return;
        }

        $note = blank($this->purchase_debit_note_id)
            ? null
            : PurchaseDebitNote::query()->find($this->purchase_debit_note_id);

        if ($note === null || $note->parent_id === null) {
            return;
        }

        $this->purchase_invoice_item_id = PurchaseInvoiceItem::query()
            ->where('purchase_invoice_id', $note->parent_id)
            ->where('product_id', $this->product_id)
            ->orderBy('line_number')
            ->value('id');
    }

    private function copyProductSnapshot(): void
    {
        $product = $this->product_id === null ? null : Product::query()->find($this->product_id);

        if ($product !== null && blank($this->expense_account_id)) {
            $this->expense_account_id = $product->expense_account_id;
        }

        if (filled($this->product_name)) {
            return;
        }

        $this->product_name = $product?->name ?: ($this->product_description ?: '—');
        $this->unit_name = $this->unit_name ?: $product?->unitType?->name;
    }
}
