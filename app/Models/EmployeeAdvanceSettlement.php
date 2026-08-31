<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash repayment of an advance — Qoyod's الدفع action on the سلفة.
 *
 * @property CarbonImmutable $settled_on
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'employee_advance_id', 'amount', 'settled_on',
    'payment_account_id', 'journal_entry_id', 'created_by_id',
])]
class EmployeeAdvanceSettlement extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'settled_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<EmployeeAdvance, $this>
     */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
