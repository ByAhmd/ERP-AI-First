<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt's money applied to one invoice.
 *
 * The row every derivation stands on: an invoice's payment state is the sum
 * of these joined to approved receipts, and a receipt's remaining advance is
 * its amount less the sum of its own rows.
 *
 * `journal_entry_id` is null when this allocation was settled inside the
 * receipt's approval entry, and set when it records a later movement of an
 * advance onto an invoice — its own accounting event, so the receipt's
 * original entry stays immutable.
 *
 * @property string $amount
 * @property int $line_number
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'customer_receipt_id', 'sales_invoice_id',
    'line_number', 'amount', 'journal_entry_id',
])]
class CustomerReceiptAllocation extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * Number a row that arrives without one — the same repeater defect the
     * invoice and credit-note items both hit, guarded in the model where every
     * path reaches it.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if (blank($row->line_number)) {
                $row->line_number = 1 + (int) static::query()
                    ->where('customer_receipt_id', $row->customer_receipt_id)
                    ->max('line_number');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
