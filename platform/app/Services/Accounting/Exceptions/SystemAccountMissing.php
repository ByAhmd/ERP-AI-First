<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Enums\SystemAccount;
use RuntimeException;

/**
 * A role the platform needs to post to has no account in this company.
 *
 * Reached when a company's chart was built by hand rather than from the
 * template, or when a keyed account was removed. Recoverable: applying the
 * template again restores anything missing without touching what exists.
 */
final class SystemAccountMissing extends RuntimeException
{
    public function __construct(
        public readonly SystemAccount $role,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forRole(SystemAccount $role): self
    {
        return new self($role, __('accounting.errors.system_account_missing', [
            'role' => $role->label(),
        ]));
    }
}
