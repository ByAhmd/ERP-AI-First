<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Models\Account;
use RuntimeException;

/**
 * Refused changes to the chart of accounts.
 *
 * These are not validation niceties. Each one prevents a structural edit from
 * silently restating figures that have already been reported or filed.
 */
final class AccountStructureViolation extends RuntimeException
{
    public static function typeChangeWithHistory(Account $account): self
    {
        return new self(__('accounting.errors.type_change_with_history', [
            'account' => $account->displayName(),
        ]));
    }

    public static function accountHasHistory(Account $account): self
    {
        return new self(__('accounting.errors.account_has_history', [
            'account' => $account->displayName(),
        ]));
    }

    public static function accountHasChildren(Account $account): self
    {
        return new self(__('accounting.errors.account_has_children', [
            'account' => $account->displayName(),
        ]));
    }

    public static function systemAccountDeletion(Account $account): self
    {
        return new self(__('accounting.errors.system_account_deletion', [
            'account' => $account->displayName(),
        ]));
    }

    public static function parentHasHistory(Account $account): self
    {
        return new self(__('accounting.errors.parent_has_history', [
            'account' => $account->displayName(),
        ]));
    }
}
