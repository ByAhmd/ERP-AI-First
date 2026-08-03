<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Identity\Exceptions\MembershipChangeRejected;
use App\Services\Identity\MembershipService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suspending and reinstating company members.
 *
 * These rules previously existed only as `visible()` conditions on the Filament
 * buttons, which hid the action but enforced nothing. Anything reaching the
 * model by another route — the API, a console command, a future import — was
 * unchecked. They are now guarded in the service and covered here.
 */
final class MembershipServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private MembershipService $memberships;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Trading']);
        app(CompanyContext::class)->set($this->company);

        $this->memberships = app(MembershipService::class);
    }

    #[Test]
    public function suspending_withdraws_access_but_keeps_the_record(): void
    {
        $this->member('admin@acme.test');
        $clerk = $this->member('clerk@acme.test');

        $suspended = $this->memberships->suspend($clerk);

        $this->assertSame(CompanyMembershipStatus::Suspended, $suspended->status);
        // The row survives so history stays attributable.
        $this->assertDatabaseHas('company_user', ['id' => $clerk->getKey()]);
        $this->assertFalse($suspended->user->fresh()->canAccessTenant($this->company));
    }

    #[Test]
    public function a_member_cannot_suspend_themselves(): void
    {
        $this->member('admin@acme.test');
        $self = $this->member('other@acme.test');

        // Previously only a hidden button. Locking yourself out of a company you
        // administer is unrecoverable without another administrator.
        $this->expectException(MembershipChangeRejected::class);

        $this->memberships->suspend($self, $self->user_id);
    }

    #[Test]
    public function the_last_active_member_cannot_be_suspended(): void
    {
        $only = $this->member('only@acme.test');

        // Suspending them leaves no one able to sign in, and inviting anyone
        // requires access.
        $this->expectException(MembershipChangeRejected::class);

        $this->memberships->suspend($only);
    }

    #[Test]
    public function an_already_suspended_member_cannot_be_suspended_again(): void
    {
        $this->member('admin@acme.test');
        $clerk = $this->member('clerk@acme.test');

        $this->memberships->suspend($clerk);

        $this->expectException(MembershipChangeRejected::class);

        $this->memberships->suspend($clerk->refresh());
    }

    #[Test]
    public function reinstating_restores_access(): void
    {
        $this->member('admin@acme.test');
        $clerk = $this->member('clerk@acme.test');

        $this->memberships->suspend($clerk);
        $reinstated = $this->memberships->reinstate($clerk->refresh());

        $this->assertSame(CompanyMembershipStatus::Active, $reinstated->status);
        $this->assertTrue($reinstated->user->fresh()->canAccessTenant($this->company));
    }

    #[Test]
    public function an_active_member_cannot_be_reinstated(): void
    {
        $this->member('admin@acme.test');
        $clerk = $this->member('clerk@acme.test');

        $this->expectException(MembershipChangeRejected::class);

        $this->memberships->reinstate($clerk);
    }

    private function member(string $email): CompanyUser
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
        ]);

        return CompanyUser::create([
            'company_id' => $this->company->getKey(),
            'user_id' => $user->getKey(),
            'status' => CompanyMembershipStatus::Active,
            'joined_at' => now(),
        ]);
    }
}
