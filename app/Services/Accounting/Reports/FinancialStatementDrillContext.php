<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;

/**
 * Everything a drill resolver needs to stay inside one column's window.
 */
final readonly class FinancialStatementDrillContext
{
    public function __construct(
        public FinancialStatement $statement,
        public int $columnIndex,
        public StatementPeriod $period,
        public ReportFilters $filters,
        public StatementOptions $options,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
