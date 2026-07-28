<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyMembershipStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Membership of a user in a company.
 *
 * Modelled explicitly rather than as an anonymous pivot because it carries
 * lifecycle of its own — invitation, acceptance, suspension — and because those
 * transitions must be audited. Who was granted access to a company's books, by
 * whom, and when, is an audit question that arises in practice.
 *
 * Scoped by {@see \App\Models\Concerns\BelongsToCompany} like every other
 * tenant-owned table. Invitation acceptance, which necessarily runs before any
 * company context exists, escapes the scope explicitly through
 * {@see \App\Support\Tenancy\CompanyContext::withoutScoping()} — a narrow,
 * greppable exception rather than a permanently unscoped table.
 *
 * Filament's own tenant scoping is treated as secondary here. Its documentation
 * states plainly that scoping applies only after tenant identification in panel
 * middleware and that multi-tenant security remains the application's
 * responsibility, so this table carries the same guard as every other.
 */
class CompanyUser extends Pivot implements AuditableContract
{
    use Auditable;
    use BelongsToCompany;
    use HasUlids;

    public $incrementing = false;

    protected $table = 'company_user';

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'user_id',
        'status',
        'invitation_token_hash',
        'invitation_expires_at',
        'invited_by_id',
        'invited_at',
        'joined_at',
    ];

    /**
     * The token hash must never reach a response, a log, or an audit record.
     */
    protected $hidden = [
        'invitation_token_hash',
    ];

    /**
     * @var array<int, string>
     */
    protected array $auditExclude = [
        'invitation_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyMembershipStatus::class,
            'invitation_expires_at' => 'datetime',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isPending(): bool
    {
        return $this->status === CompanyMembershipStatus::Invited;
    }

    public function invitationHasExpired(): bool
    {
        return $this->invitation_expires_at !== null
            && $this->invitation_expires_at->isPast();
    }

    /**
     * Whether this invitation can still be accepted.
     */
    public function invitationIsAcceptable(): bool
    {
        return $this->isPending() && ! $this->invitationHasExpired();
    }
}
