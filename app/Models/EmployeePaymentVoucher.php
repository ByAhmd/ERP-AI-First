<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\AuditsCompany;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An employee payment voucher — Qoyod's سند موظف, paying 2140 down.
 *
 * Must be fully allocated against payslips and bonuses: no unallocated
 * residue path exists through a voucher — advances are the only
 * prepayment vehicle — which is what keeps the payable reconcilable to
 * its subledger.
 *
 * @property DocumentStatus $status
 * @property CarbonImmutable $payment_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'employee_id', 'amount', 'payment_date',
    'payment_account_id', 'notes', 'status', 'journal_entry_id',
    'reversal_journal_entry_id', 'approved_at', 'approved_by_id',
    'created_by_id',
])]
class EmployeePaymentVoucher extends Model implements AuditableContract
{
    use AuditsCompany;
    use BelongsToCompany;
    use HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'payment_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<EmployeePaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(EmployeePaymentAllocation::class);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::Approved;
    }
}
