<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Illuminate\Support\Collection;

/**
 * Ledger detail behind one statement cell.
 */
final readonly class DrillDownResult
{
    /**
     * @param  Collection<int, DrillMovementRow>  $rows
     */
    public function __construct(
        public string $title,
        public string $periodLabel,
        public DrillKind $kind,
        public Collection $rows,
        public ReportFilters $filters,
        public bool $isFiltered,
        public ?string $opening = null,
        public ?string $closing = null,
        public ?string $periodDebit = null,
        public ?string $periodCredit = null,
    ) {}
}
