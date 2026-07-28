<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\CompanyMembershipStatus;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\Exceptions\CompanyMismatch;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Services\Identity\RoleProvisioner;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The members panel.
 *
 * The scoping assertions matter more than the rendering ones. Filament's own
 * tenant scoping did not apply here initially — it keys off the ownership
 * relationship, not the tenant relationship — and another company's members were
 * listed. Isolation now rests on the application's CompanyScope, with Filament's
 * scoping as a second layer, and these tests hold that line.
 */
final class MemberResourceTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $globex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are generated data rather than schema, so a refreshed
        // database has none and every policy would deny.
        $this->seed(PermissionSeeder::class);

        $this->acme = Company::create(['name' => 'Acme Trading']);
        $this->globex = Company::create(['name' => 'Globex Industrial']);

        $this->admin = $this->member($this->acme, 'admin@acme.test', 'Acme Admin');
        $this->member($this->acme, 'clerk@acme.test', 'Acme Clerk');
        $this->member($this->globex, 'rival@globex.test', 'Globex Person');

        // Filament resolves the tenant against the authenticated user, so
        // authentication has to come first.
        $this->actingAs($this->admin);

        Filament::setTenant($this->acme);
        app(CompanyContext::class)->set($this->acme);

        $this->grantAdministratorRole($this->admin, $this->acme);
    }

    /**
     * Roles are company-scoped, so the registrar must be pointed at the company
     * before the role is created or assigned.
     */
    private function grantAdministratorRole(User $user, Company $company): void
    {
        $role = app(RoleProvisioner::class)->provisionAdministrator($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $user->assignRole($role);
    }

    #[Test]
    public function it_lists_only_members_of_the_current_company(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListMembers::class)
            ->assertCanSeeTableRecords(
                CompanyUser::query()->where('company_id', $this->acme->getKey())->get(),
            )
            ->assertCanNotSeeTableRecords(
                CompanyUser::query()->where('company_id', $this->globex->getKey())->get(),
            );
    }

    #[Test]
    public function a_member_without_a_role_cannot_view_the_member_list(): void
    {
        // Shield's policy is the gate here. It is registered outside Laravel's
        // discovery convention, so if that registration is ever lost the
        // resource fails open — this test detects that.
        $clerk = User::query()->where('email', 'clerk@acme.test')->firstOrFail();

        Livewire::actingAs($clerk)
            ->test(ListMembers::class)
            ->assertForbidden();
    }

    #[Test]
    public function the_member_list_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListMembers::class)
            ->assertOk()
            ->assertSee('admin@acme.test')
            ->assertSee('clerk@acme.test')
            ->assertDontSee('rival@globex.test');
    }

    #[Test]
    public function suspending_a_member_revokes_their_access_to_the_company(): void
    {
        $membership = CompanyUser::query()
            ->where('company_id', $this->acme->getKey())
            ->whereHas('user', fn ($q) => $q->where('email', 'clerk@acme.test'))
            ->firstOrFail();

        $this->assertTrue($membership->user->canAccessTenant($this->acme));

        $membership->update(['status' => CompanyMembershipStatus::Suspended]);

        $this->assertFalse($membership->user->fresh()->canAccessTenant($this->acme));
    }

    #[Test]
    public function a_suspended_member_still_appears_in_the_list(): void
    {
        // History must remain attributable; suspension is not deletion.
        $membership = CompanyUser::query()
            ->where('company_id', $this->acme->getKey())
            ->whereHas('user', fn ($q) => $q->where('email', 'clerk@acme.test'))
            ->firstOrFail();

        $membership->update(['status' => CompanyMembershipStatus::Suspended]);

        Livewire::actingAs($this->admin)
            ->test(ListMembers::class)
            ->assertSee('clerk@acme.test');
    }

    #[Test]
    public function membership_cannot_be_reassigned_to_another_company(): void
    {
        $membership = CompanyUser::query()
            ->where('company_id', $this->acme->getKey())
            ->firstOrFail();

        $membership->company_id = $this->globex->getKey();

        // Blocked at the model layer by BelongsToCompany, not merely hidden by
        // the panel. Moving a membership would transfer access to another
        // company's books.
        $this->expectException(CompanyMismatch::class);

        $membership->save();
    }

    private function member(Company $company, string $email, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'irrelevant-for-this-test',
        ]);

        CompanyUser::create([
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
            'status' => CompanyMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return $user->refresh();
    }
}
