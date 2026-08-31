<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceSubtype;
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
 * @property InvoiceSubtype $subtype
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
    'company_id', 'reference', 'status', 'subtype', 'contact_id', 'parent_id',
    'branch_id',
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

    /**
     * A credit note's subtype follows the invoice it credits.
     *
     * Derived, never chosen: a simplified invoice is corrected by a simplified
     * credit note and a standard one by a standard note, whatever the form
     * said. Only a note against an external original — where there is no
     * parent to follow — keeps the subtype it was given.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $note): void {
            if ($note->parent_id === null) {
                return;
            }

            $parentSubtype = SalesInvoice::query()
                ->whereKey($note->parent_id)
                ->value('subtype');

            if ($parentSubtype !== null) {
                $note->subtype = $parentSubtype;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'subtype' => InvoiceSubtype::class,
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
