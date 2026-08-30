<?php

declare(strict_types=1);

namespace App\Models;

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
 * A purchase debit note — إشعار مدين.
 *
 * Reduces what we owe a supplier, and is the only correction available for
 * an approved bill. The original invoice identity it carries is the
 * SUPPLIER's number — the one a supplier-statement reconciliation is done
 * with — inherited from the parent bill when one is named.
 *
 * @property DocumentStatus $status
 * @property CarbonImmutable $issue_date
 * @property ?CarbonImmutable $original_invoice_date
 * @property string $subtotal_net
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'status', 'contact_id', 'parent_id',
    'original_invoice_number', 'original_invoice_date',
    'issue_date',
    'description', 'terms_and_conditions', 'notes',
    'subtotal_net', 'discount_total', 'tax_total', 'total',
    'currency_id', 'exchange_rate', 'journal_entry_id',
    'approved_at', 'approved_by_id', 'created_by_id',
])]
class PurchaseDebitNote extends Model implements AuditableContract
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
     * A note against a bill this platform holds inherits that bill's
     * supplier-invoice identity — derived, never typed, so the note keeps
     * naming the right paper even if the form left the field alone.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $note): void {
            if ($note->parent_id === null) {
                return;
            }

            $parent = PurchaseInvoice::query()->find($note->parent_id);

            if ($parent === null) {
                return;
            }

            $note->original_invoice_number = $parent->supplier_invoice_number ?? $parent->reference;
            $note->original_invoice_date = $parent->supplier_invoice_date ?? $parent->issue_date;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'issue_date' => 'date',
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
     * @return HasMany<PurchaseDebitNoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseDebitNoteItem::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The bill being corrected, when it is one this platform holds.
     *
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'parent_id');
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
