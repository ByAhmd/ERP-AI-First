<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Enums\NormalBalance;

/**
 * One account's line on a trial balance.
 *
 * Opening, movement and closing are each presented as a debit/credit pair
 * rather than a signed number, because that is the form a trial balance takes
 * and the form in which its two columns can be seen to agree.
 *
 * Amounts are strings throughout — the same bcmath discipline as the posting
 * engine. A report that re-introduces floats would disagree with the ledger it
 * is reporting on.
 */
final readonly class TrialBalanceRow
{
    public function __construct(
        public string $accountId,
        public string $code,
        public string $name,
        public AccountType $type,
        public string $openingDebit,
        public string $openingCredit,
        public string $periodDebit,
        public string $periodCredit,
        public string $closingDebit,
        public string $closingCredit,
    ) {}

    public function normalBalance(): NormalBalance
    {
        return $this->type->normalBalance();
    }

    /**
     * The closing balance as a single signed figure in the account's natural
     * direction: positive when the account sits on its normal side.
     */
    public function closingSigned(): string
    {
        $net = bcsub($this->closingDebit, $this->closingCredit, 4);

        return $this->normalBalance() === NormalBalance::Debit
            ? $net
            : bcmul($net, '-1', 4);
    }

    public function hasActivity(): bool
    {
        return bccomp($this->periodDebit, '0', 4) !== 0
            || bccomp($this->periodCredit, '0', 4) !== 0;
    }

    public function hasBalance(): bool
    {
        return bccomp($this->closingDebit, '0', 4) !== 0
            || bccomp($this->closingCredit, '0', 4) !== 0;
    }
}
