<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\CompanyMembershipStatus;
use App\Models\CompanyUser;
use App\Services\Identity\Exceptions\MembershipChangeRejected;

/**
 * Changes to a person's standing within a company.
 *
 * Suspension is not deletion: the membership row stays so that the audit trail
 * and every posting the person made remain attributable. Only their access is
 * withdrawn.
 *
 * The rules below previously lived in the Filament table as `visible()`
 * conditions, which hid the buttons but enforced nothing. A visibility rule is a
 * courtesy to the user, not a guard — anything reaching the model by another
 * route was unchecked.
 */
final class MembershipService
{
    /**
     * Withdraw a member's access while keeping their history.
     *
     * @param  string|null  $actingUserId  The user performing the change.
     */
    public function suspend(CompanyUser $membership, ?string $actingUserId = null): CompanyUser
    {
        if ($membership->status !== CompanyMembershipStatus::Active) {
            throw MembershipChangeRejected::notActive($membership);
        }

        // Locking yourself out of a company you administer is unrecoverable
        // without another administrator, so it is refused rather than warned
        // about.
        if ($actingUserId !== null && $membership->user_id === $actingUserId) {
            throw MembershipChangeRejected::cannotSuspendSelf();
        }

        if ($this->isLastActiveMember($membership)) {
            throw MembershipChangeRejected::lastActiveMember();
        }

        $membership->forceFill(['status' => CompanyMembershipStatus::Suspended])->save();

        return $membership->refresh();
    }

    /**
     * Restore a suspended member's access.
     */
    public function reinstate(CompanyUser $membership): CompanyUser
    {
        if ($membership->status !== CompanyMembershipStatus::Suspended) {
            throw MembershipChangeRejected::notSuspended($membership);
        }

        $membership->forceFill(['status' => CompanyMembershipStatus::Active])->save();

        return $membership->refresh();
    }

    /**
     * Whether this is the only person who can still reach the company.
     *
     * Suspending them would leave the company with no one able to sign in and
     * no way to invite anyone, since inviting requires access.
     */
    private function isLastActiveMember(CompanyUser $membership): bool
    {
        return CompanyUser::query()
            ->where('company_id', $membership->company_id)
            ->where('status', CompanyMembershipStatus::Active->value)
            ->whereKeyNot($membership->getKey())
            ->doesntExist();
    }
}
