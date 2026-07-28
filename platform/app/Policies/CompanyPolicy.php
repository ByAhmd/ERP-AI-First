<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\User;

/**
 * Authorisation for the company record itself.
 *
 * Two conditions must both hold for any action: the user is an active member of
 * that specific company, and they hold the corresponding permission. Membership
 * alone is not enough, and a permission held in one company grants nothing in
 * another.
 *
 * The permission checked is `View:CompanySettings`. Company is administered
 * through a Filament page rather than a resource — within a tenant panel there
 * is only ever one company in scope — and Shield generates a single `View:`
 * permission per page. Naming that one permission here, rather than inventing
 * `Update:Company` which nothing would ever create, keeps a single source of
 * truth: a permission that does not exist silently denies everything.
 */
class CompanyPolicy
{
    /**
     * Permission covering administration of the current company's settings.
     */
    public const ADMINISTER = 'View:CompanySettings';

    public function viewAny(User $user): bool
    {
        return $user->can(self::ADMINISTER);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->isActiveMember($user, $company)
            && $user->can(self::ADMINISTER);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->isActiveMember($user, $company)
            && $user->can(self::ADMINISTER);
    }

    /**
     * Companies are never deleted from within their own panel.
     *
     * A company owns ledgers, invoices and statutory filings that must remain
     * retrievable long after it stops trading. Ending a relationship is a status
     * change, which is platform administration, not a delete.
     */
    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return false;
    }

    private function isActiveMember(User $user, Company $company): bool
    {
        return $user->companies()
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->whereKey($company->getKey())
            ->exists();
    }
}
