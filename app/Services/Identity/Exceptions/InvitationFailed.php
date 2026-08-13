<?php

declare(strict_types=1);

namespace App\Services\Identity\Exceptions;

use RuntimeException;

/**
 * Invitation lifecycle failures that a user can meaningfully act on.
 */
final class InvitationFailed extends RuntimeException
{
    public static function alreadyAMember(string $email): self
    {
        return new self(__('identity.invitations.already_a_member', ['email' => $email]));
    }

    /**
     * Deliberately does not distinguish "unknown", "expired" and "already
     * accepted". Telling a caller which one it was lets them probe for valid
     * tokens.
     */
    public static function invalidToken(): self
    {
        return new self(__('identity.invitations.invalid_token'));
    }

    public static function notPending(): self
    {
        return new self(__('identity.invitations.not_pending'));
    }
}
