<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyMembershipStatus;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Permission\Traits\HasRoles;

/**
 * A platform identity.
 *
 * Users are not owned by a company. The same accountant commonly serves several
 * clients, so membership is expressed through the `company_user` pivot and roles
 * are held per company via spatie/laravel-permission's teams feature.
 */
class User extends Authenticatable implements AuditableContract, FilamentUser, HasTenants
{
    use Auditable;
    use HasApiTokens;
    use HasRoles;
    use HasUlids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'is_platform_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Mirrors the schema defaults.
     *
     * Without these, a freshly created model carries no value for columns the
     * database defaults, and any code reading them before a reload fails under
     * strict mode — which is exactly what happens to locale resolution
     * immediately after registration.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'ar',
        'is_platform_admin' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot(['id', 'status', 'invited_at', 'joined_at', 'invited_by_id'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /**
     * Companies this user may currently act for.
     *
     * Filament renders these in the company switcher. Invited and suspended
     * memberships are excluded, so an unaccepted invitation grants nothing.
     *
     * @return Collection<int, Company>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->companies()
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->get();
    }

    /**
     * Authoritative membership check.
     *
     * Filament calls this on every panel request that carries a company, which
     * is what makes company selection unforgeable — the identifier in the URL is
     * only honoured if this returns true.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Company) {
            return false;
        }

        return $this->companies()
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->whereKey($tenant->getKey())
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Membership of at least one company, or platform administration.
        return $this->is_platform_admin || $this->companies()
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->exists();
    }
}
