<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonInterface;

/**
 * One journal line inside a drill-down panel.
 */
final readonly class DrillMovementRow
{
    public function __construct(
        public string $entryId,
        public string $number,
        public CarbonInterface $date,
        public ?string $description,
        public ?string $reference,
        public string $debit,
        public string $credit,
        public ?string $accountLabel = null,
        public ?string $runningBalance = null,
    ) {}

    public static function fromLedgerMovement(LedgerMovement $movement, ?string $accountLabel = null): self
    {
        return new self(
            entryId: $movement->entryId,
            number: $movement->number,
            date: $movement->date,
            description: $movement->description,
            reference: $movement->reference,
            debit: $movement->debit,
            credit: $movement->credit,
            accountLabel: $accountLabel,
            runningBalance: $movement->balance,
        );
    }
}
