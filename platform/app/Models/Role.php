<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Seeders\FirstRunSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A company-scoped role.
 *
 * Spatie's own Role model carries the team key as a bare column with no
 * relationship. Because this application renames that key to `company_id` and
 * runs Filament with tenancy, anything that scopes roles by their owning company
 * — Filament's global search among others — needs a real `company()` relation to
 * traverse. Without it, rendering the panel topbar fails.
 *
 * Registered through `config('permission.models.role')` so that Spatie, Shield
 * and this application all resolve the same class.
 */
class Role extends SpatieRole
{
    /**
     * Roles are scoped by spatie/laravel-permission's own team handling rather
     * than {@see BelongsToCompany}. Adding the global scope
     * here would double-filter and break role resolution during authentication,
     * which runs before a company is selected.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Whether this role applies across every company.
     *
     * Such roles are a privilege-escalation risk and are removed by
     * {@see FirstRunSeeder}; the accessor exists so the
     * condition can be asserted and surfaced rather than merely cleaned up.
     */
    public function isGlobal(): bool
    {
        return $this->company_id === null;
    }
}
