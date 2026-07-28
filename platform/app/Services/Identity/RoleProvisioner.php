<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps each company's administrator role in step with the permission set.
 *
 * Permissions are generated per Filament resource by `shield:generate`, which
 * knows nothing about companies. Roles, by contrast, are company-scoped. Without
 * a reconciliation step every phase that adds resources would leave existing
 * companies' administrators unable to reach the new screens — the role would
 * still be called `super_admin` while quietly holding a stale permission set.
 *
 * Run after `shield:generate`, and whenever a company is created.
 */
final class RoleProvisioner
{
    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * Ensure the company has an administrator role holding every permission.
     */
    public function provisionAdministrator(Company $company): Role
    {
        return DB::transaction(function () use ($company): Role {
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

            $role = Role::firstOrCreate([
                'name' => $this->administratorRoleName(),
                'guard_name' => 'web',
                'company_id' => $company->getKey(),
            ]);

            // syncPermissions rather than givePermissionTo: permissions removed
            // from the application should disappear from the role too, or the
            // role accumulates grants for screens that no longer exist.
            $role->syncPermissions(Permission::all());

            return $role;
        });
    }

    /**
     * Reconcile every company. Used by the console command after regeneration.
     *
     * @return int Number of companies processed.
     */
    public function provisionAllCompanies(): int
    {
        return $this->context->withoutScoping(function (): int {
            $count = 0;

            Company::query()->chunkById(100, function ($companies) use (&$count): void {
                foreach ($companies as $company) {
                    $this->provisionAdministrator($company);
                    $count++;
                }
            });

            return $count;
        });
    }

    private function administratorRoleName(): string
    {
        return (string) config('filament-shield.super_admin.name', 'super_admin');
    }
}
