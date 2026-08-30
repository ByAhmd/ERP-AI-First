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
 * A customer receipt — سند قبض.
 *
 * Money received, deposited into a payment account the user chose, and
 * allocated across the customer's invoices. Whatever is not allocated is held
 * as a customer advance — a liability, because it is their money until an
 * allocation turns it into settlement.
 *
 * No stored balance: how much of the receipt is used derives from its
 * allocation rows, exactly as an invoice's payment state derives from the
 * receipts against it.
 *
 * @property DocumentStatus $status
 * @property CarbonImmutable $receipt_date
 * @property string $amount
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'status', 'contact_id', 'deposit_account_id',
    'receipt_date', 'payment_method', 'payment_reference', 'amount',
    'description', 'notes', 'currency_id', 'exchange_rate',
    'journal_entry_id', 'approved_at', 'approved_by_id', 'created_by_id',
])]
class CustomerReceipt extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'receipt_date' => 'date',
            'approved_at' => 'datetime',
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<CustomerReceiptAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function depositAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_account_id');
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
     * The sum of this receipt's allocation rows.
     */
    public function allocatedTotal(): string
    {
        $sum = $this->allocations()->sum('amount');

        return bcadd((string) ($sum ?: '0'), '0', 4);
    }

    /**
     * What is not yet applied to any invoice — the advance.
     */
    public function unallocatedAmount(): string
    {
        return bcsub((string) $this->amount, $this->allocatedTotal(), 4);
    }
}
