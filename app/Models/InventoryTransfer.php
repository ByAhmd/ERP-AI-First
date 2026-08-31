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
 * An inventory transfer — نقل مخزون.
 *
 * Quantities moving between branches at the company-wide average. Nothing
 * posts: with one inventory account, the ledger's net effect is zero, so
 * the transfer's whole record is its stock movements. Drafts are edited;
 * an approved transfer moved real goods and is corrected by a transfer
 * back, never by edit.
 *
 * @property DocumentStatus $status
 * @property CarbonImmutable $transfer_date
 * @property ?string $company_id
 */
#[Fillable([
    'company_id', 'reference', 'status',
    'from_branch_id', 'to_branch_id',
    'transfer_date', 'description',
    'approved_at', 'approved_by_id', 'created_by_id',
])]
class InventoryTransfer extends Model implements AuditableContract
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
            'transfer_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InventoryTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
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
