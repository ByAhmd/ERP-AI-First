<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One voucher's money applied to one bill.
 *
 * The row every derivation stands on: a bill's payment state is the sum of
 * these joined to approved vouchers, and a voucher's remaining advance is
 * its amount less the sum of its own rows.
 *
 * `journal_entry_id` is null when this allocation was settled inside the
 * voucher's approval entry, and set when it records a later movement of an
 * advance onto a bill — its own accounting event, so the voucher's original
 * entry stays immutable.
 *
 * @property string $amount
 * @property int $line_number
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'supplier_payment_id', 'purchase_invoice_id',
    'line_number', 'amount', 'journal_entry_id',
])]
class SupplierPaymentAllocation extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * Number a row that arrives without one — the repeater defect, guarded
     * in the model where every path reaches it.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if (blank($row->line_number)) {
                $row->line_number = 1 + (int) static::query()
                    ->where('supplier_payment_id', $row->supplier_payment_id)
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
     * @return BelongsTo<SupplierPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
