<?php

declare(strict_types=1);

namespace App\Services\Assets\Exceptions;

use App\Models\Account;
use RuntimeException;

/**
 * An asset registration or type configuration that cannot be accepted.
 */
final class AssetRuleViolation extends RuntimeException
{
    public static function accountNotPostable(Account $account): self
    {
        return new self(__('assets.errors.account_not_postable', [
            'account' => $account->displayName(),
        ]));
    }

    public static function accountTypeMismatch(Account $account, string $expected): self
    {
        return new self(__('assets.errors.account_type_mismatch', [
            'account' => $account->displayName(),
            'expected' => $expected,
        ]));
    }

    public static function notPaymentAccount(Account $account): self
    {
        return new self(__('assets.errors.not_payment_account', [
            'account' => $account->displayName(),
        ]));
    }

    public static function salvageExceedsCost(): self
    {
        return new self(__('assets.errors.salvage_exceeds_cost'));
    }

    public static function openingAccumulatedTooLarge(): self
    {
        return new self(__('assets.errors.opening_accumulated_too_large'));
    }

    public static function openingAccumulatedNeedsDate(): self
    {
        return new self(__('assets.errors.opening_accumulated_needs_date'));
    }

    public static function lifeRequired(): self
    {
        return new self(__('assets.errors.life_required'));
    }

    public static function purchaseCarriesNoAccumulated(): self
    {
        return new self(__('assets.errors.purchase_carries_no_accumulated'));
    }
}
