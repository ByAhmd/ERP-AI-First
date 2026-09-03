<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * Where a drillable statement row comes from in the ledger.
 *
 * Carried on the row at build time so execution reuses the same account scope
 * the amount was computed from — never reverse-engineered from the displayed
 * figure.
 */
final readonly class StatementDrillTarget
{
    /**
     * @param  list<string>  $accountIds  Empty means non-drillable at execution time.
     */
    public function __construct(
        public DrillKind $kind,
        public array $accountIds = [],
        public bool $subtree = false,
    ) {}

    public static function account(DrillKind $kind, string $accountId): self
    {
        return new self(kind: $kind, accountIds: [$accountId]);
    }

    public static function subtree(DrillKind $kind, string $rootAccountId): self
    {
        return new self(kind: $kind, accountIds: [$rootAccountId], subtree: true);
    }

    public function isDrillable(): bool
    {
        return $this->accountIds !== [];
    }
}
