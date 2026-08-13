<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
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
 * A sales credit note.
 *
 * Reduces what a customer owes, and is the only correction available for an
 * approved invoice — which is why the invoice itself has no edit path once
 * posted. Both documents stay visible afterwards, as they must: the customer
 * holds the invoice.
 *
 * @property DocumentStatus $status
 * @property CreditNoteReason $reason_code
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable $event_date
 * @property string $subtotal_net
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'status', 'contact_id', 'parent_id',
    'original_invoice_number', 'original_invoice_date',
    'issue_date', 'due_date', 'event_date',
    'reason_code', 'reason_text',
    'description', 'terms_and_conditions', 'notes',
    'subtotal_net', 'discount_total', 'tax_total', 'total',
    'currency_id', 'exchange_rate', 'journal_entry_id',
    'approved_at', 'approved_by_id', 'created_by_id',
])]
class SalesCreditNote extends Model implements AuditableContract
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

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'reason_code' => CreditNoteReason::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'event_date' => 'date',
            'original_invoice_date' => 'date',
            'approved_at' => 'datetime',
            'subtotal_net' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<SalesCreditNoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesCreditNoteItem::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The invoice being credited, when it is one this platform holds.
     *
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'parent_id');
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

    public function totalsReconcile(): bool
    {
        return bccomp(
            (string) $this->total,
            bcadd((string) $this->subtotal_net, (string) $this->tax_total, 4),
            4,
        ) === 0;
    }
}
