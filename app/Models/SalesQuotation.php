<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A sales quotation — عرض سعر.
 *
 * A commercial document with the invoice's arithmetic and none of its
 * accounting. It carries lines, totals and a validity window; it never touches
 * the ledger at any status, which is why it has no journal entry relation to
 * misuse. Its one exit into the books is conversion, which creates a separate
 * draft invoice and freezes this document as provenance.
 *
 * No soft deletes: drafts may be deleted outright, and anything past draft is
 * kept — an approved offer is a document the customer holds, and an invoiced
 * one is pointed at by the invoice's provenance link.
 *
 * @property QuotationStatus $status
 * @property CarbonImmutable $issue_date
 * @property CarbonImmutable $expiry_date
 * @property string $subtotal_net
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'status', 'contact_id',
    'issue_date', 'expiry_date',
    'description', 'terms_and_conditions', 'notes',
    'subtotal_net', 'discount_total', 'tax_total', 'total',
    'currency_id', 'exchange_rate',
    'approved_at', 'approved_by_id', 'created_by_id',
])]
class SalesQuotation extends Model implements AuditableContract
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
            'status' => QuotationStatus::class,
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'approved_at' => 'datetime',
            'subtotal_net' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<SalesQuotationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesQuotationItem::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * The invoice this quotation became, if it has.
     *
     * The link lives on the invoice, where a unique index holds the one-shot
     * rule. Provenance only: nothing reads through it to produce a figure.
     *
     * @return HasOne<SalesInvoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(SalesInvoice::class, 'quotation_id');
    }

    public function isDraft(): bool
    {
        return $this->status === QuotationStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === QuotationStatus::Approved;
    }

    /**
     * Whether the offer has lapsed.
     *
     * Derived from the date and the clock, never stored: a stored expired
     * status needs a scheduler to flip it, and a scheduler that silently was
     * not running is how a March price survives into June. Invoiced and
     * cancelled quotations are never "expired" — those states already
     * resolved the offer.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date->isPast()
            && in_array($this->status, [QuotationStatus::Draft, QuotationStatus::Approved], true);
    }

    /**
     * Whether the stored totals still agree with the lines.
     *
     * Read by the tests rather than by the application, exactly as on the
     * invoice: the recalculator writes both in one transaction.
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
