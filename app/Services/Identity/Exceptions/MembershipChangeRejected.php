<?php

declare(strict_types=1);

namespace App\Services\Identity\Exceptions;

use App\Models\CompanyUser;
use RuntimeException;

/**
 * A membership change that would lock someone out, or that does not apply.
 */
final class MembershipChangeRejected extends RuntimeException
{
    public static function notActive(CompanyUser $membership): self
    {
        return new self(__('identity.members.errors.not_active', [
            'status' => $membership->status->getLabel(),
        ]));
    }

    public static function notSuspended(CompanyUser $membership): self
    {
        return new self(__('identity.members.errors.not_suspended', [
            'status' => $membership->status->getLabel(),
        ]));
    }

    public static function cannotSuspendSelf(): self
    {
        return new self(__('identity.members.errors.cannot_suspend_self'));
    }

    public static function lastActiveMember(): self
    {
        return new self(__('identity.members.errors.last_active_member'));
    }
}
