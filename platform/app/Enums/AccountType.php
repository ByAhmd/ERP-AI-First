<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The five account classifications of double-entry bookkeeping.
 *
 * Each type fixes two things that the rest of the ledger depends on: which side
 * of the entry increases the balance, and which financial statement the account
 * belongs to. Both are derived here rather than stored, so an account cannot be
 * configured into an inconsistent state.
 */
enum AccountType: string implements HasColor, HasLabel
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function getLabel(): string
    {
        return __("accounting.account_type.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Asset => 'info',
            self::Liability => 'warning',
            self::Equity => 'primary',
            self::Revenue => 'success',
            self::Expense => 'danger',
        };
    }

    /**
     * The side on which this type increases.
     *
     * Assets and expenses are debit-normal; liabilities, equity and revenue are
     * credit-normal. Reports use this to present a balance as a positive figure
     * regardless of the sign it carries in the ledger.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }

    /**
     * Whether balances carry forward across fiscal years.
     *
     * Balance-sheet accounts accumulate for the life of the company. Income and
     * expense accounts are closed to retained earnings at year end and reopen at
     * zero — which is what makes a profit-and-loss statement period-specific.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Revenue, self::Expense => false,
        };
    }

    public function appearsOnBalanceSheet(): bool
    {
        return $this->isPermanent();
    }

    public function appearsOnIncomeStatement(): bool
    {
        return ! $this->isPermanent();
    }
}
