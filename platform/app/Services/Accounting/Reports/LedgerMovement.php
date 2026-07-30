<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonInterface;

/**
 * One line of an account's ledger, with the balance after it.
 *
 * The running balance is what distinguishes a general ledger from a list of
 * movements: it answers "what did this account stand at on that date", which is
 * the question asked when reconciling a bank statement or explaining a figure to
 * an auditor.
 *
 * Amounts are strings, keeping the same bcmath discipline as the posting engine.
 */
final readonly class LedgerMovement
{
    public function __construct(
        public string $entryId,
        public string $number,
        public CarbonInterface $date,
        public ?string $description,
        public ?string $reference,
        public string $debit,
        public string $credit,
        public string $balance,
        public ?string $counterAccounts = null,
    ) {}

    /**
     * The balance expressed in the account's natural direction.
     *
     * A supplier account sitting at 5,000 credit reads as 5,000 owed, not
     * minus 5,000.
     */
    public function absoluteBalance(): string
    {
        return ltrim($this->balance, '-');
    }

    public function isCredit(): bool
    {
        return bccomp($this->balance, '0', 4) < 0;
    }
}
