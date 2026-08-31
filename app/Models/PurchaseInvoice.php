<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A purchase invoice — فاتورة مشتريات.
 *
 * The mirror of SalesInvoice: an accounting record and the register of what a
 * supplier's document said when it was keyed. The totals stored here are what
 * posted; they are written by the recalculator inside the transaction that
 * writes the lines and never recomputed on read.
 *
 * No soft deletes. An approved bill is part of the ledger and is corrected by
 * debit note, exactly as an invoice is corrected by credit note.
 *
 * @property DocumentStatus $status
 * @property PurchaseInvoiceKind $kind
 * @property CarbonImmutable $issue_date
 * @property ?CarbonImmutable $due_date
 * @property ?CarbonImmutable $supplier_invoice_date
 * @property string $subtotal_net
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'kind', 'status', 'contact_id', 'purchase_order_id',
    'branch_id',
    'supplier_invoice_number', 'supplier_invoice_date',
    'issue_date', 'due_date',
    'description', 'terms_and_conditions', 'notes',
    'subtotal_net', 'discount_total', 'tax_total', 'total',
    'currency_id', 'exchange_rate', 'journal_entry_id',
    'approved_at', 'approved_by_id', 'created_by_id',
])]
class PurchaseInvoice extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'standard',
        'status' => 'draft',
        'subtotal_net' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    /**
     * Deleting a still-draft converted bill releases its purchase order.
     *
     * Conversion flips the order to Billed in the same transaction that
     * creates this draft. If the draft is then discarded, the order reverts
     * to Approved — nothing stays stuck pointing at a row that no longer
     * exists, and the unique index then permits an honest re-conversion.
     * Only drafts are deletable, so this can never fire for a posted bill.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $invoice): void {
            if ($invoice->purchase_order_id === null) {
                return;
            }

            PurchaseOrder::query()
                ->whereKey($invoice->purchase_order_id)
                ->where('status', PurchaseOrderStatus::Billed)
                ->update(['status' => PurchaseOrderStatus::Approved]);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'kind' => PurchaseInvoiceKind::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'supplier_invoice_date' => 'date',
            'approved_at' => 'datetime',
            'subtotal_net' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<PurchaseInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The purchase order this bill was converted from, if any.
     *
     * Provenance only: the bill snapshots everything it needs at
     * conversion, and nothing reads back through this to produce a figure.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Approved);
    }

    /**
     * How much of the bill remains owed, and the state that implies.
     *
     * Nothing is stored — the same rule as the sales invoice, for the same
     * reason: a stored payment status goes stale on every event that changes
     * the answer. The list attaches `amount_paid` and `amount_debited`
     * through BillOutstanding::decorate(); an undecorated model reads them as
     * zero and shows every settled bill as unpaid, so the caller should have
     * decorated.
     */
    public function paymentStatus(): string
    {
        $paid = bcadd((string) ($this->getAttribute('amount_paid') ?? '0'), '0', 4);
        $debited = bcadd((string) ($this->getAttribute('amount_debited') ?? '0'), '0', 4);

        $outstanding = bcsub(
            bcsub((string) $this->total, $debited, 4),
            $paid,
            4,
        );

        if (bccomp($outstanding, '0', 4) <= 0) {
            return 'paid';
        }

        if (bccomp(bcadd($paid, $debited, 4), '0', 4) > 0) {
            return 'partially_paid';
        }

        return 'unpaid';
    }

    /**
     * Whether the stored totals still agree with the lines.
     */
    public function totalsReconcile(): bool
    {
        return bccomp(
            (string) $this->total,
            bcadd((string) $this->subtotal_net, (string) $this->tax_total, 4),
            4,
        ) === 0;
    }
}
