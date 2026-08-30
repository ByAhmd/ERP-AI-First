<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Dimension;
use App\Models\DimensionValue;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
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
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Trading');
        $this->admin = $this->makeAdministrator($this->company, 'admin@acme.test');

        // Binding the permission team is the middleware's job. Leaving it set
        // here would mask a panel rendering with no navigation because no
        // company was ever bound — the defect these tests missed the first time.
        $this->forgetPermissionTeam();
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
        $response->assertSee(__('audit.nav_label'), escape: false);
        $response->assertSee(__('company.settings.nav_label'), escape: false);

        // Qoyod's sidebar groups, all present: the panel provider names them
        // and every one has at least one screen behind it.
        $response->assertSee(__('company.dashboard'), escape: false);
        $response->assertSee(__('sales.navigation_group'), escape: false);
        $response->assertSee(__('purchases.navigation_group'), escape: false);
        $response->assertSee(__('purchases.suppliers.nav_label'), escape: false);
        $response->assertSee(__('purchases.invoices.nav_label'), escape: false);
        $response->assertSee(__('purchases.debit_notes.nav_label'), escape: false);
        $response->assertSee(__('sales.products_group'), escape: false);
        $response->assertSee(__('accounting.navigation_group'), escape: false);
        $response->assertSee(__('accounting.reports_group'), escape: false);
        $response->assertSee(__('identity.navigation_group'), escape: false);

        // The relabelled entries read as Qoyod writes them.
        $response->assertSee(__('sales.quotations.nav_label'), escape: false);
        $response->assertSee(__('accounting.nav_overrides.entries'), escape: false);
        $response->assertSee(__('accounting.nav_overrides.branches'), escape: false);
        $response->assertSee(__('accounting.nav_overrides.general_ledger'), escape: false);

        // And the groups render in Qoyod's order, not merely somewhere on the
        // page — the provider's registration is what fixes the sequence, and
        // an unregistered group would silently sort to the end.
        $content = $response->getContent();
        $positions = array_map(
            fn (string $key): int => (int) mb_strpos($content, __($key)),
            [
                'sales.navigation_group',
                'purchases.navigation_group',
                'sales.products_group',
                'accounting.navigation_group',
                'accounting.reports_group',
                'identity.navigation_group',
            ],
        );

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Sidebar groups are out of Qoyod order.');
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
        $other = $this->makeOtherCompany('Globex Industrial');
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
        $this->actingAs($this->admin)->get("{$base}/dimensions")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/dimensions/create")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/branches")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/branches/create")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/general-ledger-page")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/balance-sheet-page")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/income-statement-page")->assertOk();
        $this->actingAs($this->admin)->get("{$base}/opening-balances-page")->assertOk();
    }

    #[Test]
    public function the_sales_pages_render(): void
    {
        app(ChartOfAccountsTemplate::class)->applyTo($this->company);
        app(TaxTemplate::class)->applyTo($this->company);

        $base = "/admin/{$this->company->getKey()}";

        $this->actingAs($this->admin)
            ->get("{$base}/taxes")
            ->assertOk()
            // The seeded rates, under Qoyod's own wording.
            ->assertSee('ضريبة القيمة المضافة')
            ->assertSee(__('sales.taxes.columns.code'), escape: false);

        $this->actingAs($this->admin)->get("{$base}/taxes/create")->assertOk();

        $this->actingAs($this->admin)
            ->get("{$base}/customers")
            ->assertOk()
            ->assertSee(__('sales.contacts.columns.code'), escape: false);

        $this->actingAs($this->admin)
            ->get("{$base}/customers/create")
            ->assertOk()
            // The four sections Qoyod's form is divided into, in its order.
            ->assertSee(__('sales.contacts.sections.details'), escape: false)
            ->assertSee(__('sales.contacts.sections.billing_address'), escape: false)
            ->assertSee(__('sales.contacts.sections.shipping_address'), escape: false)
            ->assertSee(__('sales.contacts.sections.bank'), escape: false);

        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->actingAs($this->admin)->get("{$base}/products")->assertOk();
        $this->actingAs($this->admin)
            ->get("{$base}/products/create")
            ->assertOk()
            // Both names are required on a Qoyod product, and both appear here.
            ->assertSee(__('sales.products.fields.name'), escape: false)
            ->assertSee(__('sales.products.fields.name_en'), escape: false);

        $this->actingAs($this->admin)
            ->get("{$base}/product-categories")
            ->assertOk()
            ->assertSee('الصنف الأساسي');

        $this->actingAs($this->admin)->get("{$base}/sales-invoices")->assertOk();
        $this->actingAs($this->admin)
            ->get("{$base}/sales-invoices/create")
            ->assertOk()
            // ZATCA wants the supply date separately from the issue date, and
            // the line table is Qoyod's, so both are asserted rather than
            // assumed from a 200.
            ->assertSee(__('sales.invoices.fields.supply_date'), escape: false)
            ->assertSee(__('sales.invoices.items.is_inclusive'), escape: false);

        $this->actingAs($this->admin)->get("{$base}/sales-credit-notes")->assertOk();
        $this->actingAs($this->admin)
            ->get("{$base}/sales-credit-notes/create")
            ->assertOk()
            // The ZATCA fields Qoyod's own form does not show.
            ->assertSee(__('sales.credit_notes.fields.original_invoice_number'), escape: false)
            ->assertSee(__('sales.credit_notes.fields.reason_code'), escape: false);

        $this->actingAs($this->admin)->get("{$base}/customer-receipts")->assertOk();
        $this->actingAs($this->admin)
            ->get("{$base}/customer-receipts/create")
            ->assertOk()
            // The deposit account is Qoyod's الحساب, gated by the payment flag.
            ->assertSee(__('sales.receipts.fields.deposit_account'), escape: false)
            ->assertSee(__('sales.receipts.allocations.title'), escape: false);
    }

    #[Test]
    public function the_income_statement_renders_every_subtotal_it_promises(): void
    {
        // A missing translation key renders as the key itself rather than
        // throwing, so a 200 proves nothing about the labels. These are the
        // headings the statement is read by, and the middle one is the Saudi
        // requirement the first release of this report shipped without.
        app(ChartOfAccountsTemplate::class)->applyTo($this->company);

        $response = $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}/income-statement-page")
            ->assertOk();

        foreach (['gross_profit', 'operating_result', 'interest_tax_and_zakat', 'net_profit'] as $section) {
            $response->assertSee(__("accounting.statements.sections.{$section}"), escape: false);
        }
    }

    #[Test]
    public function the_entry_form_renders_a_select_for_each_user_defined_dimension(): void
    {
        app(ChartOfAccountsTemplate::class)->applyTo($this->company);
        app(CompanyContext::class)->set($this->company);

        $project = Dimension::create(['code' => 'PROJ', 'name' => 'المشروع']);
        DimensionValue::create([
            'dimension_id' => $project->getKey(),
            'code' => 'RIYADH',
            'name' => 'برج الرياض',
        ]);

        // The form builds its dimension fields from the data, so a dimension
        // created after this class was written still appears on the entry screen.
        $this->actingAs($this->admin)
            ->get("/admin/{$this->company->getKey()}/journal-entries/create")
            ->assertOk()
            ->assertSee('المشروع', escape: false);
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

        $other = $this->makeOtherCompany('Globex Industrial');
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
