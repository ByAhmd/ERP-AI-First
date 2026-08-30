<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
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
 * A purchase order — أمر شراء.
 *
 * A commercial document with the bill's arithmetic and none of its
 * accounting. It never touches the ledger at any status; its one exit into
 * the books is conversion, which creates a separate draft bill and freezes
 * this document as provenance.
 *
 * @property PurchaseOrderStatus $status
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
class PurchaseOrder extends Model implements AuditableContract
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
            'status' => PurchaseOrderStatus::class,
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
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('line_number');
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
     * The bill this order became, if it has.
     *
     * The link lives on the bill, where a unique index holds the one-shot
     * rule. Provenance only.
     *
     * @return HasOne<PurchaseInvoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(PurchaseInvoice::class, 'purchase_order_id');
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseOrderStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === PurchaseOrderStatus::Approved;
    }

    /**
     * Whether the order has lapsed unbilled — Qoyod's متأخرة.
     *
     * Derived from the date and the clock, never stored: a stored overdue
     * status needs a scheduler to flip it. Billed and cancelled orders are
     * never overdue — those states already resolved the order.
     */
    public function isOverdue(): bool
    {
        return $this->expiry_date->isPast()
            && in_array($this->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved], true);
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
