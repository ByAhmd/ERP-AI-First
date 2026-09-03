<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * Points at one figure the statement already computed.
 *
 * Resolved at drill time against the built statement (or the income statement
 * when a cash-flow or equity line reuses it), so the breakdown never recomputes
 * arithmetic the report service already performed.
 */
final readonly class StatementDrillReference
{
    private function __construct(
        public ?string $sectionKey = null,
        public ?string $summaryKey = null,
        public ?string $incomeSummaryKey = null,
        public ?string $incomeSectionKey = null,
        public ?StatementDrillTarget $ledger = null,
    ) {}

    public static function section(string $key): self
    {
        return new self(sectionKey: $key);
    }

    public static function summary(string $key): self
    {
        return new self(summaryKey: $key);
    }

    public static function incomeSummary(string $key): self
    {
        return new self(incomeSummaryKey: $key);
    }

    public static function incomeSection(string $key): self
    {
        return new self(incomeSectionKey: $key);
    }

    public static function ledger(StatementDrillTarget $target): self
    {
        return new self(ledger: $target);
    }
}
