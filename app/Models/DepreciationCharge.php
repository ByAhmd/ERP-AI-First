<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One posted depreciation charge — an asset, a period of record, an amount.
 *
 * The stored subledger. POSTED rows only: the forward schedule is a display
 * projection with nothing to defend, but every posted charge is a fact the
 * clamp, the terminal remainder and the disposal all read back. The unique
 * (asset, period-of-record) index is the run's idempotency anchor.
 *
 * `accounting_period_id` is the period the charge belongs to;
 * `posted_period_id` is where the money landed — they differ when a catch-up
 * crosses a closed period, and recording both is what keeps as-of reports
 * honest about it.
 *
 * Integer primary key, the stock_movements deviation-with-reason: charges are
 * an append stream and their application order is part of the audit trail.
 *
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'fixed_asset_id', 'accounting_period_id', 'posted_period_id',
    'depreciation_run_id', 'journal_entry_id', 'amount', 'days',
])]
class DepreciationCharge extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /**
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function postedPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'posted_period_id');
    }

    /**
     * @return BelongsTo<DepreciationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
