<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Notifications\CompanyInvitation;
use App\Services\Identity\Exceptions\InvitationFailed;
use App\Services\Identity\InvitationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The invitation flow.
 *
 * An invitation grants access to a company's complete financial history, so it
 * is treated as a credential: hashed at rest, time-limited, and single-use.
 */
final class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private InvitationService $invitations;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->company = Company::create(['name' => 'Acme Trading']);
        $this->invitations = app(InvitationService::class);
    }

    #[Test]
    public function it_creates_a_pending_membership_and_notifies_the_invitee(): void
    {
        $membership = $this->invitations->invite($this->company, 'new@example.test', 'Sara');

        $this->assertSame(CompanyMembershipStatus::Invited, $membership->status);
        $this->assertNull($membership->joined_at);
        $this->assertNotNull($membership->invitation_expires_at);

        Notification::assertSentTo($membership->user, CompanyInvitation::class);
    }

    #[Test]
    public function it_never_stores_the_plaintext_token(): void
    {
        $this->invitations->invite($this->company, 'new@example.test', 'Sara');

        $stored = $this->membership()->invitation_token_hash;

        // 64 hex characters is a SHA-256 digest, not a 64-byte token.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $stored);
    }

    #[Test]
    public function an_invited_user_cannot_yet_access_the_company(): void
    {
        $membership = $this->invitations->invite($this->company, 'new@example.test', 'Sara');

        $this->assertFalse($membership->user->canAccessTenant($this->company));
    }

    #[Test]
    public function accepting_activates_the_membership_and_sets_the_password(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.test');

        $membership = $this->invitations->accept($token, 'Correct-Horse-9!battery');

        $this->assertSame(CompanyMembershipStatus::Active, $membership->status);
        $this->assertNotNull($membership->joined_at);
        $this->assertTrue(Hash::check('Correct-Horse-9!battery', $membership->user->password));
        $this->assertTrue($membership->user->fresh()->canAccessTenant($this->company));
    }

    #[Test]
    public function a_token_cannot_be_used_twice(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.test');
        $this->invitations->accept($token, 'Correct-Horse-9!battery');

        $this->expectException(InvitationFailed::class);

        $this->invitations->accept($token, 'Another-Password-1!x');
    }

    #[Test]
    public function an_expired_invitation_is_not_acceptable(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.test');

        $this->membership()->forceFill([
            'invitation_expires_at' => now()->subMinute(),
        ])->save();

        $this->assertNull($this->invitations->findByToken($token));
    }

    #[Test]
    public function it_refuses_to_reinvite_an_active_member(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.test');
        $this->invitations->accept($token, 'Correct-Horse-9!battery');

        $this->expectException(InvitationFailed::class);

        $this->invitations->invite($this->company, 'new@example.test', 'Sara');
    }

    #[Test]
    public function it_reissues_a_pending_invitation_without_duplicating_membership(): void
    {
        $this->invitations->invite($this->company, 'new@example.test', 'Sara');
        $this->invitations->invite($this->company, 'new@example.test', 'Sara');

        $this->assertSame(1, $this->unscoped(fn (): int => CompanyUser::query()->count()));
        $this->assertSame(1, User::query()->where('email', 'new@example.test')->count());
    }

    #[Test]
    public function the_acceptance_page_renders_for_a_valid_token(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.test');

        $this->get(route('invitations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Acme Trading')
            ->assertSee('new@example.test');
    }

    #[Test]
    public function the_acceptance_page_rejects_an_unknown_token(): void
    {
        $this->get(route('invitations.show', ['token' => str_repeat('a', 64)]))
            ->assertOk()
            ->assertSee(__('identity.invitations.accept.invalid_heading'));
    }

    #[Test]
    public function acceptance_rejects_a_weak_password(): void
    {
        $token = $this->inviteAndCaptureToken('new@example.test');

        $this->post(route('invitations.accept', ['token' => $token]), [
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertSame(CompanyMembershipStatus::Invited, $this->membership()->status);
    }

    /**
     * Read the membership without a company context.
     *
     * These tests exercise the service, not the panel, so no company is
     * selected — and CompanyScope correctly resolves that to no rows. The
     * escape is explicit here for the same reason the service uses it.
     */
    private function membership(): CompanyUser
    {
        return $this->unscoped(fn (): CompanyUser => CompanyUser::query()->firstOrFail());
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function unscoped(\Closure $callback): mixed
    {
        return app(CompanyContext::class)->withoutScoping($callback);
    }

    /**
     * Recover the plaintext token from the queued notification, which is the
     * only place it exists after issuance.
     */
    private function inviteAndCaptureToken(string $email): string
    {
        $membership = $this->invitations->invite($this->company, $email, 'Sara');

        $token = null;

        Notification::assertSentTo(
            $membership->user,
            CompanyInvitation::class,
            function (CompanyInvitation $notification) use (&$token): bool {
                $token = (new \ReflectionProperty($notification, 'token'))->getValue($notification);

                return true;
            },
        );

        return (string) $token;
    }
}
