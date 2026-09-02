<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * A rendered financial statement.
 *
 * The balance sheet and the income statement differ in what they measure and
 * agree on how they present it: a set of columns, a sequence of sections, and
 * figures aligned between them. Sharing this shape means one view renders both,
 * and a presentation fix lands on both at once.
 */
final readonly class FinancialStatement
{
    /**
     * @param  list<StatementPeriod>  $periods
     * @param  list<StatementSection>  $sections
     * @param  ?list<string>  $imbalance  Per column, for statements that must reconcile.
     * @param  ?string  $imbalanceMessage  Translation key when {@see $imbalance} is non-zero.
     */
    public function __construct(
        public array $periods,
        public array $sections,
        public bool $isFiltered = false,
        public ?array $imbalance = null,
        public ?string $imbalanceMessage = null,
    ) {}

    public function columnCount(): int
    {
        return count($this->periods);
    }

    public function hasComparisons(): bool
    {
        return count($this->periods) > 1;
    }

    /**
     * Whether the statement is required to balance and does.
     *
     * Null when the question does not apply — an income statement has nothing
     * to reconcile, and neither does a balance sheet narrowed by branch or
     * dimension, since those select individual journal lines and an entry
     * spanning two branches contributes only part of itself to either. Reporting
     * such a statement as broken would be wrong; reporting it as balanced would
     * be a claim this report cannot make.
     */
    public function isBalanced(): ?bool
    {
        if ($this->imbalance === null || $this->isFiltered) {
            return null;
        }

        foreach ($this->imbalance as $difference) {
            if (bccomp($difference, '0', 4) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The largest discrepancy across the columns, for reporting a failure.
     */
    public function largestImbalance(): string
    {
        $largest = '0.0000';

        foreach ($this->imbalance ?? [] as $difference) {
            $magnitude = bccomp($difference, '0', 4) < 0
                ? bcmul($difference, '-1', 4)
                : $difference;

            if (bccomp($magnitude, $largest, 4) > 0) {
                $largest = $magnitude;
            }
        }

        return $largest;
    }
}
