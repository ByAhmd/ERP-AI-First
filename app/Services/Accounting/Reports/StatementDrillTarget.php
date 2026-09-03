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
     * @param  list<string>  $accountIds
     * @param  list<StatementDrillPart>  $parts
     */
    public function __construct(
        public DrillKind $kind,
        public array $accountIds = [],
        public bool $subtree = false,
        public array $parts = [],
        public ?string $sectionKey = null,
    ) {}

    public static function account(DrillKind $kind, string $accountId): self
    {
        return new self(kind: $kind, accountIds: [$accountId]);
    }

    public static function subtree(DrillKind $kind, string $rootAccountId): self
    {
        return new self(kind: $kind, accountIds: [$rootAccountId], subtree: true);
    }

    /**
     * @param  list<StatementDrillPart>  $parts
     */
    public static function composite(array $parts): self
    {
        return new self(kind: DrillKind::Composite, parts: $parts);
    }

    public static function sectionBreakdown(string $sectionKey): self
    {
        return new self(kind: DrillKind::SectionBreakdown, sectionKey: $sectionKey);
    }

    public function isDrillable(): bool
    {
        return match ($this->kind) {
            DrillKind::Composite => $this->parts !== [],
            DrillKind::SectionBreakdown => $this->sectionKey !== null,
            default => $this->accountIds !== [],
        };
    }
}
