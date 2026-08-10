<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Models\Account;
use App\Models\FiscalYear;
use RuntimeException;

/**
 * Opening balances that cannot be accepted.
 *
 * Each condition is caught before anything reaches the ledger, because an
 * opening balance is the foundation every later figure stands on — a mistake
 * here is not one wrong entry, it is every report being wrong by the same
 * amount for as long as the company uses the platform.
 */
final class OpeningBalanceRejected extends RuntimeException
{
    /**
     * Posted opening balances are part of the ledger and share its
     * immutability. Correction is by reversal, like any other posted entry.
     */
    public static function alreadyPosted(FiscalYear $year): self
    {
        return new self(__('accounting.opening_balances.errors.already_posted', [
            'year' => $year->name,
        ]));
    }

    public static function nothingToRecord(FiscalYear $year): self
    {
        return new self(__('accounting.opening_balances.errors.nothing_to_record', [
            'year' => $year->name,
        ]));
    }

    /**
     * The account was not among those offered — a temporary account, an
     * inactive one, a group, or the suspense account itself.
     */
    public static function ineligibleAccount(string $accountId): self
    {
        return new self(__('accounting.opening_balances.errors.ineligible_account', [
            'account' => $accountId,
        ]));
    }

    public static function twoSidedBalance(Account $account): self
    {
        return new self(__('accounting.opening_balances.errors.two_sided', [
            'account' => $account->code.' '.$account->name,
        ]));
    }
}
