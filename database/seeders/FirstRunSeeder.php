<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Identity\RoleProvisioner;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings a fresh installation to a usable state: one company, one administrator,
 * and the roles that company's permissions hang off.
 *
 * Idempotent — re-running it updates rather than duplicates, so it is safe to
 * include in a deployment pipeline.
 *
 * Credentials come from the environment. A seeder that hard-codes a password is
 * a production incident waiting to happen, and the predecessor system shipped
 * exactly that.
 */
class FirstRunSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('erp.seed.admin_email');
        $password = (string) config('erp.seed.admin_password');

        if (blank($email) || blank($password)) {
            $this->command?->warn(
                'Skipping first-run seed: SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD are not set.',
            );

            return;
        }

        DB::transaction(function () use ($email, $password): void {
            $this->removeUnscopedRoles();

            $company = $this->seedCompany();
            $admin = $this->seedAdministrator($email, $password);

            $this->grantMembership($company, $admin);
            $this->grantSuperAdminRole($company, $admin);
            $this->seedAccounting($company);
        });

        $this->command?->info('First-run seed complete.');
    }

    /**
     * Remove roles that belong to no company.
     *
     * `shield:generate` runs during setup, before any company exists, and writes
     * its roles with a null company. Under spatie/laravel-permission's teams
     * mode a null team is not "unassigned" — it is *global*, matching in every
     * company. A stray global `super_admin` is a privilege-escalation waiting to
     * be assigned by accident, so it is cleared here.
     *
     * Only unassigned roles are removed; a role someone actually holds is left
     * alone and reported, because silently revoking access would be worse than
     * the problem.
     */
    private function removeUnscopedRoles(): void
    {
        $unscoped = Role::query()->whereNull('company_id')->get();

        foreach ($unscoped as $role) {
            $assignments = DB::table('model_has_roles')->where('role_id', $role->getKey())->count();

            if ($assignments > 0) {
                $this->command?->warn(
                    "Role [{$role->name}] is global and assigned to {$assignments} model(s). "
                    .'Left in place — reassign it to a company and remove it manually.',
                );

                continue;
            }

            $role->delete();
        }
    }

    /**
     * Give the company a usable ledger: a chart of accounts and a fiscal year.
     *
     * Without both, nothing can be posted — the chart because postings need
     * accounts, and the calendar because a posting date must fall inside an
     * open period. A company that can sign in but cannot record a transaction
     * is not usable.
     */
    private function seedAccounting(Company $company): void
    {
        app(ChartOfAccountsTemplate::class)->applyTo($company);

        // After the chart, never before: a tax names the account it posts to,
        // and that account has to exist first.
        app(TaxTemplate::class)->applyTo($company);

        // A product needs a category, a unit and a rate before it can exist.
        app(CatalogueTemplate::class)->applyTo($company);

        $calendar = app(FiscalCalendar::class);

        app(CompanyContext::class)->forCompany($company, function () use ($calendar, $company): void {
            if ($calendar->findYear(now()) === null) {
                $calendar->createYear($company, (int) now()->year);
            }
        });
    }

    private function seedCompany(): Company
    {
        // Companies are not company-scoped, but creating one before any context
        // exists is exactly the situation the scope guards against elsewhere.
        return app(CompanyContext::class)->withoutScoping(
            fn (): Company => Company::firstOrCreate(
                ['name' => (string) config('erp.seed.company_name')],
                [
                    'name_en' => (string) config('erp.seed.company_name_en'),
                    'base_currency' => config('erp.base_currency'),
                    'timezone' => config('erp.timezone'),
                    'country_code' => 'SA',
                ],
            ),
        );
    }

    private function seedAdministrator(string $email, string $password): User
    {
        $admin = User::firstOrNew(['email' => $email]);

        $admin->fill([
            'name' => (string) config('erp.seed.admin_name'),
            'is_platform_admin' => true,
        ]);

        // Only set the password on first creation, so re-seeding never resets a
        // password an operator has since changed.
        if (! $admin->exists) {
            $admin->password = $password;
        }

        $admin->save();

        return $admin->refresh();
    }

    private function grantMembership(Company $company, User $admin): void
    {
        if ($company->users()->whereKey($admin->getKey())->exists()) {
            return;
        }

        $company->users()->attach($admin, [
            'id' => (string) Str::ulid(),
            'status' => CompanyMembershipStatus::Active->value,
            'joined_at' => now(),
        ]);
    }

    /**
     * Roles are company-scoped, so the permission registrar must be told which
     * company the role belongs to before it is created or assigned. Omitting
     * this silently creates a role against no company, which then matches
     * nothing at authorisation time.
     */
    private function grantSuperAdminRole(Company $company, User $admin): void
    {
        // Provisioning also syncs the permission set onto the role. Creating the
        // role alone would leave an administrator who can sign in but reach
        // nothing, because Shield's super admin holds permissions explicitly
        // rather than bypassing the gate.
        $role = app(RoleProvisioner::class)->provisionAdministrator($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        if (! $admin->hasRole($role)) {
            $admin->assignRole($role);
        }
    }
}
