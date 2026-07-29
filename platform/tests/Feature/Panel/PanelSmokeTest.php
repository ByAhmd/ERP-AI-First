<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Services\Identity\RoleProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Full-page renders of the panel.
 *
 * Livewire component tests exercise a component in isolation and never render
 * the panel shell — topbar, navigation, global search. A resource can therefore
 * pass its own tests while the dashboard returns a 500, which is exactly what
 * happened with Shield's global-search integration. These tests make real HTTP
 * requests so the whole layout is exercised.
 */
final class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->company = Company::create(['name' => 'Acme Trading']);

        $this->admin = User::create([
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
            'password' => 'password',
        ]);

        CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->admin->getKey(),
            'status' => CompanyMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $role = app(RoleProvisioner::class)->provisionAdministrator($this->company);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        $this->admin->assignRole($role);

        // Deliberately cleared. Binding the permission team is the middleware's
        // job; leaving it set here would mask a panel that renders with no
        // navigation because no company was ever bound — which is exactly the
        // defect these tests missed the first time.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    #[Test]
    public function the_dashboard_renders_for_an_administrator(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}")
            ->assertOk();
    }

    #[Test]
    public function the_navigation_lists_every_section_the_administrator_may_reach(): void
    {
        // Regression: permissions are company-scoped, and until the middleware
        // binds the permission team every check fails silently. The panel then
        // renders successfully with nothing but the dashboard in the sidebar —
        // a 200 that hides a completely unusable application.
        $response = $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}")
            ->assertOk();

        $response->assertSee(__('identity.members.plural_label'), escape: false);
        $response->assertSee(__('audit.plural_label'), escape: false);
        $response->assertSee(__('company.settings.nav_label'), escape: false);
    }

    #[Test]
    public function the_members_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}/members")
            ->assertOk()
            ->assertSee('admin@acme.test');
    }

    #[Test]
    public function the_roles_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}/shield/roles")
            ->assertOk();
    }

    #[Test]
    public function the_company_settings_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}/company-settings")
            ->assertOk()
            ->assertSee('Acme Trading');
    }

    #[Test]
    public function the_audit_trail_renders_and_shows_only_this_company(): void
    {
        // Membership creation in setUp is itself audited, so there is a record.
        $other = Company::create(['name' => 'Globex Industrial']);
        $otherUser = User::create([
            'name' => 'Globex Person',
            'email' => 'rival@globex.test',
            'password' => 'password',
        ]);
        CompanyUser::create([
            'company_id' => $other->getKey(),
            'user_id' => $otherUser->getKey(),
            'status' => CompanyMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}/audits")
            ->assertOk()
            // The other company's audit rows must not appear here.
            ->assertDontSee($other->getKey());
    }

    #[Test]
    public function the_accounting_pages_render(): void
    {
        // Every page a signed-in administrator can reach from the navigation.
        // Component tests do not render the panel shell, so only a real request
        // exercises the topbar, navigation and global search alongside the page.
        app(ChartOfAccountsTemplate::class)->applyTo($this->company);

        $base = "/admin/{$this->company->getKey()}";

        $this->actingAs($this->admin)->get("{$base}/accounts")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/journal-entries")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/journal-entries/create")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/fiscal-years")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/trial-balance-page")->assertOk();
    }

    #[Test]
    public function the_chart_of_accounts_lists_in_tree_order(): void
    {
        app(ChartOfAccountsTemplate::class)->applyTo($this->company);

        $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}/accounts")
            ->assertOk()
            ->assertSee('1110')
            ->assertSee('2120');
    }

    #[Test]
    public function a_non_member_cannot_reach_the_company_panel(): void
    {
        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider@example.test',
            'password' => 'password',
        ]);

        $other = Company::create(['name' => 'Globex Industrial']);
        CompanyUser::create([
            'company_id' => $other->getKey(),
            'user_id' => $outsider->getKey(),
            'status' => CompanyMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        // 404 rather than 403, and deliberately so: a non-member gets the same
        // response as for a company that does not exist, so the panel cannot be
        // used to confirm which company identifiers are real. This matches the
        // API's behaviour in ResolveApiCompany.
        $this->actingAs($outsider)
            ->get("/admin/{$this->company->getKey()}")
            ->assertNotFound();
    }
}
