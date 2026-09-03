<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * A named block of a financial statement.
 *
 * Most sections list accounts and total them. A few carry no accounts at all —
 * gross profit, net profit, the liabilities-and-equity check — because they are
 * arithmetic over the sections above rather than anything the ledger holds.
 * Both are the same shape here so a statement renders as one uniform sequence
 * instead of a view full of special cases.
 */
final readonly class StatementSection
{
    /**
     * @param  string  $key  Resolves the heading through the translation catalogue.
     * @param  list<StatementLine>  $lines
     * @param  list<string>  $totals  One per statement column.
     */
    public function __construct(
        public string $key,
        public array $lines,
        public array $totals,
        public bool $isSummary = false,
        public bool $isEmphasised = false,
        public ?StatementDrillTarget $drill = null,
    ) {}

    /**
     * A section that states a figure without listing anything.
     *
     * @param  list<string>  $totals
     */
    public static function summary(
        string $key,
        array $totals,
        bool $emphasised = false,
        ?StatementDrillTarget $drill = null,
    ): self {
        return new self(
            key: $key,
            lines: [],
            totals: $totals,
            isSummary: true,
            isEmphasised: $emphasised,
            drill: $drill,
        );
    }

    public function title(): string
    {
        return __("accounting.statements.sections.{$this->key}");
    }

    public function totalLabel(): string
    {
        return __('accounting.statements.total', ['section' => $this->title()]);
    }

    public function isDrillable(): bool
    {
        return $this->drill?->isDrillable() ?? false;
    }
}
