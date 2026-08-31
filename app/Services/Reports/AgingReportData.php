<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * A built aging report: its column dates, its rows, and its totals row.
 *
 * `foreignCount` is the number of documents priced in a foreign currency that
 * contributed to the primary column at face value — the report warns rather
 * than converting, because a converted figure would no longer tie to the
 * ledger it must reconcile with.
 */
final readonly class AgingReportData
{
    /**
     * @param  list<CarbonImmutable>  $dates
     * @param  list<AgingRow>  $rows
     * @param  list<array{amount: string, count: int}>  $totals
     */
    public function __construct(
        public array $dates,
        public array $rows,
        public array $totals,
        public int $foreignCount = 0,
    ) {}
}
