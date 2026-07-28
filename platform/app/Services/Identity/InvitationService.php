<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Notifications\CompanyInvitation;
use App\Services\Identity\Exceptions\InvitationFailed;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Issues, accepts and revokes company invitations.
 *
 * An invitation grants access to a company's complete financial history, so the
 * token is treated as a credential: generated with a CSPRNG, stored only as a
 * SHA-256 hash, time-limited, and single-use.
 */
final class InvitationService
{
    /**
     * Plaintext token length in bytes before hex encoding.
     */
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * Issue an invitation, creating the user record if this email is new.
     *
     * Returns the membership; the plaintext token is delivered by notification
     * and is never returned to the caller, so it cannot be logged accidentally.
     */
    public function invite(
        Company $company,
        string $email,
        string $name,
        ?Role $role = null,
        ?User $invitedBy = null,
    ): CompanyUser {
        $existing = $this->context->forCompany($company, fn (): ?CompanyUser => $company->memberships()
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->first());

        if ($existing !== null && $existing->status === CompanyMembershipStatus::Active) {
            throw InvitationFailed::alreadyAMember($email);
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $membership = DB::transaction(function () use (
            $company, $email, $name, $role, $invitedBy, $token, $existing
        ): CompanyUser {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    // Set on acceptance. Until then the account cannot be used:
                    // an unusable hash never matches any supplied password.
                    'password' => Str::random(64),
                ],
            );

            $membership = $existing ?? new CompanyUser([
                'company_id' => $company->getKey(),
                'user_id' => $user->getKey(),
            ]);

            $membership->fill([
                'company_id' => $company->getKey(),
                'user_id' => $user->getKey(),
                'status' => CompanyMembershipStatus::Invited,
                'invitation_token_hash' => $this->hash($token),
                'invitation_expires_at' => now()->addDays(
                    (int) config('erp.invitations.expires_after_days', 7),
                ),
                'invited_by_id' => $invitedBy?->getKey(),
                'invited_at' => now(),
                'joined_at' => null,
            ]);

            $membership->save();

            if ($role !== null) {
                $this->assignRole($company, $user, $role);
            }

            return $membership;
        });

        $membership->user->notify(new CompanyInvitation($company, $token));

        return $membership->refresh();
    }

    /**
     * Find the membership a token refers to, if it is still acceptable.
     *
     * Lookup is by hash, so a stolen database yields no usable tokens.
     */
    public function findByToken(string $token): ?CompanyUser
    {
        // Acceptance happens before any company is selected — the recipient is
        // not yet a member of anything — so the company scope must be escaped
        // explicitly. The token is the authorisation, and it is single-use,
        // hashed and time-limited.
        $membership = $this->context->withoutScoping(
            fn (): ?CompanyUser => CompanyUser::query()
                ->where('invitation_token_hash', $this->hash($token))
                ->first(),
        );

        if ($membership === null || ! $membership->invitationIsAcceptable()) {
            return null;
        }

        return $membership;
    }

    /**
     * Accept an invitation and set the account's password.
     *
     * The token is cleared in the same transaction, making acceptance
     * single-use even if the link is replayed.
     */
    public function accept(string $token, string $password): CompanyUser
    {
        $membership = $this->findByToken($token) ?? throw InvitationFailed::invalidToken();

        return DB::transaction(function () use ($membership, $password): CompanyUser {
            $user = $membership->user;
            $user->password = $password;
            $user->save();

            $membership->forceFill([
                'status' => CompanyMembershipStatus::Active,
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'joined_at' => now(),
            ])->save();

            return $membership;
        });
    }

    /**
     * Withdraw a pending invitation.
     */
    public function revoke(CompanyUser $membership): void
    {
        if (! $membership->isPending()) {
            throw InvitationFailed::notPending();
        }

        $membership->delete();
    }

    /**
     * Roles are company-scoped, so the registrar must be told which company
     * before assignment; otherwise the role is written against none and matches
     * nothing at authorisation time.
     */
    private function assignRole(Company $company, User $user, Role $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        $user->assignRole($role);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
