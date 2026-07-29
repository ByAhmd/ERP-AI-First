<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one movement within a journal entry.
 *
 * Debit and credit are kept as separate columns rather than a single signed
 * amount. That is how a ledger is read, printed and audited, and it removes any
 * question about which direction a negative number was meant to represent.
 */
class JournalEntryLine extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $fillable = [
        'company_id',
        'journal_entry_id',
        'account_id',
        'line_number',
        'description',
        'debit',
        'credit',
        'currency_id',
        'foreign_debit',
        'foreign_credit',
        'exchange_rate',
        'branch_id',
        'cost_center_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'debit' => 0,
        'credit' => 0,
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'foreign_debit' => 'decimal:4',
            'foreign_credit' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<CostCenter, $this>
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function isDebit(): bool
    {
        return bccomp((string) $this->debit, '0', 4) > 0;
    }

    /**
     * The movement as a signed figure, positive for debits.
     *
     * Used by reporting, which sums a single column rather than two.
     */
    public function signedAmount(): string
    {
        return bcsub((string) $this->debit, (string) $this->credit, 4);
    }
}
