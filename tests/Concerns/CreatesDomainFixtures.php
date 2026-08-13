<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Identity\RoleProvisioner;
use App\Support\Tenancy\CompanyContext;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixture helpers for domain tests.
 *
 * Twelve test classes were each building a company, a membership, a role and a
 * fiscal calendar by hand, three of them in setUp methods running past thirty
 * lines. The scaffolding is identical every time and says nothing about what
 * the test is checking, so it lives here instead.
 *
 * Order matters in several of these and is easy to get wrong in isolation:
 * permissions must exist before a role is provisioned, and Filament resolves
 * its tenant against the authenticated user, so authentication comes first.
 */
trait CreatesDomainFixtures
{
    /**
     * A company with the company context already bound to it.
     *
     * Almost every test needs the context set; leaving it to the caller is what
     * produced the scattered `app(CompanyContext::class)->set(...)` lines.
     */
    protected function makeCompany(string $name = 'شركة الأفق للتجارة'): Company
    {
        $company = Company::create(['name' => $name]);

        app(CompanyContext::class)->set($company);

        return $company;
    }

    /**
     * A second company, without disturbing the bound context.
     *
     * Isolation tests need one of these, and creating it must not silently
     * re-point the context at it — that would make the test pass for the wrong
     * reason.
     */
    protected function makeOtherCompany(string $name = 'شركة الغد الصناعية'): Company
    {
        $context = app(CompanyContext::class);
        $current = $context->company();

        $other = Company::create(['name' => $name]);

        if ($current !== null) {
            $context->set($current);
        }

        return $other;
    }

    /**
     * An active member of a company.
     */
    protected function makeMember(Company $company, string $email, string $name = 'عضو'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
            'status' => CompanyMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return $user->refresh();
    }

    /**
     * A member who can reach every screen in the panel.
     *
     * Seeds permissions first: they are generated data rather than schema, so a
     * refreshed database has none and the provisioned role would sync an empty
     * set — leaving an administrator who can sign in and reach nothing.
     */
    protected function makeAdministrator(Company $company, string $email = 'admin@example.test'): User
    {
        $this->seed(PermissionSeeder::class);

        $admin = $this->makeMember($company, $email, 'مسؤول');

        $role = app(RoleProvisioner::class)->provisionAdministrator($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        $admin->assignRole($role);

        return $admin;
    }

    /**
     * Sign in and select a company, in the order Filament requires.
     *
     * The tenant is resolved against the authenticated user, so authenticating
     * second leaves Filament with nothing to check membership against.
     */
    protected function actingInPanel(User $user, Company $company): static
    {
        $this->actingAs($user);

        Filament::setTenant($company);
        app(CompanyContext::class)->set($company);

        return $this;
    }

    /**
     * Clear the permission team binding.
     *
     * Binding it is the middleware's job. A test that leaves it set masks a
     * panel rendering with no navigation because no company was ever bound —
     * the defect the first panel tests missed entirely.
     */
    protected function forgetPermissionTeam(): static
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this;
    }

    /**
     * A chart of accounts for the company.
     */
    protected function makeChartOfAccounts(Company $company): void
    {
        app(ChartOfAccountsTemplate::class)->applyTo($company);
    }

    /**
     * A fiscal year with its twelve periods.
     */
    protected function makeFiscalYear(Company $company, int $year = 2026): FiscalYear
    {
        return app(FiscalCalendar::class)->createYear($company, $year);
    }

    /**
     * A company ready to post to: chart of accounts and an open fiscal year.
     */
    protected function makeAccountingCompany(int $year = 2026): Company
    {
        $company = $this->makeCompany();

        $this->makeChartOfAccounts($company);
        $this->makeFiscalYear($company, $year);

        return $company;
    }
}
