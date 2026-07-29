<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The side of an account on which its balance normally sits.
 *
 * Derived from {@see AccountType} rather than configured, so an account can
 * never be set up with a normal balance that contradicts its classification.
 */
enum NormalBalance: string implements HasLabel
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return __("accounting.normal_balance.{$this->value}");
    }

    /**
     * Multiplier that converts a raw ledger movement into a balance in this
     * account's natural direction.
     *
     * Debit-normal accounts are (debits − credits); credit-normal accounts are
     * the inverse. Reporting multiplies by this so a healthy revenue account
     * reads as a positive figure rather than a negative one.
     */
    public function signum(): int
    {
        return $this === self::Debit ? 1 : -1;
    }

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
