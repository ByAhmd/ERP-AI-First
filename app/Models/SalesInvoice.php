<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceSubtype;
use App\Enums\QuotationStatus;
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
 * A sales invoice.
 *
 * Two documents in one: an accounting record and a thing a customer holds. The
 * totals stored here are what both must agree on, which is why they are written
 * by the posting service inside the transaction that writes the lines and are
 * never recomputed on read.
 *
 * No soft deletes. An approved invoice is part of the ledger and is corrected
 * by credit note, exactly as a posted journal entry is corrected by reversal.
 *
 * @property DocumentStatus $status
 * @property InvoiceSubtype $subtype
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable $supply_date
 * @property string $subtotal_net
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property ?string $branch_id
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'status', 'subtype', 'contact_id', 'quotation_id',
    'branch_id',
    'issue_date', 'due_date', 'supply_date',
    'description', 'terms_and_conditions', 'notes',
    'subtotal_net', 'discount_total', 'tax_total', 'total',
    'currency_id', 'exchange_rate', 'journal_entry_id',
    'approved_at', 'approved_by_id', 'created_by_id',
])]
class SalesInvoice extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'subtotal_net' => 0,
        'discount_total' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    /**
     * Deleting a still-draft converted invoice releases its quotation.
     *
     * Conversion flips the quotation to Invoiced in the same transaction that
     * creates this draft. If the draft is then discarded, the quotation must
     * not stay stuck pointing at a row that no longer exists — it reverts to
     * Approved, and the unique index on quotation_id then permits an honest
     * re-conversion. Only drafts are deletable, so this can never fire for an
     * invoice that reached the ledger.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $invoice): void {
            if ($invoice->quotation_id === null) {
                return;
            }

            SalesQuotation::query()
                ->whereKey($invoice->quotation_id)
                ->where('status', QuotationStatus::Invoiced)
                ->update(['status' => QuotationStatus::Approved]);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'subtype' => InvoiceSubtype::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'supply_date' => 'date',
            'approved_at' => 'datetime',
            'subtotal_net' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<SalesInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class)->orderBy('line_number');
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
     * The quotation this invoice was converted from, if any.
     *
     * Provenance only, the same rule as a line's tax_id: kept for the audit
     * trail, never read to produce a figure or a display name — the invoice
     * snapshots everything it needs at conversion.
     *
     * @return BelongsTo<SalesQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class);
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
     * How much of the invoice remains owed, and the state that implies.
     *
     * Nothing is stored: a stored payment status goes stale on every event
     * that changes the answer — a receipt approved, a credit note approved, an
     * allocation released. The list attaches `amount_received` and
     * `amount_credited` as subqueries through InvoiceOutstanding::decorate();
     * a single loaded invoice may call this with those attributes absent, in
     * which case they read as zero and the caller should have decorated.
     */
    public function paymentStatus(): string
    {
        $received = bcadd((string) ($this->getAttribute('amount_received') ?? '0'), '0', 4);
        $credited = bcadd((string) ($this->getAttribute('amount_credited') ?? '0'), '0', 4);

        $outstanding = bcsub(
            bcsub((string) $this->total, $credited, 4),
            $received,
            4,
        );

        if (bccomp($outstanding, '0', 4) <= 0) {
            return 'paid';
        }

        if (bccomp(bcadd($received, $credited, 4), '0', 4) > 0) {
            return 'partially_paid';
        }

        return 'unpaid';
    }

    /**
     * Whether the stored totals still agree with the lines.
     *
     * Read by the tests rather than by the application: the service writes both
     * in one transaction, so a disagreement is a bug rather than a state to
     * handle at runtime.
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
