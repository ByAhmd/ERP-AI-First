<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * One contact's line on an aging report.
 *
 * One cell per column date, each an amount with the count of contributing
 * documents beside it — Qoyod's `250,000 (5)` presentation. Amounts are
 * strings throughout, the posting engine's bcmath discipline.
 */
final readonly class AgingRow
{
    /**
     * @param  list<array{amount: string, count: int}>  $cells
     */
    public function __construct(
        public string $contactId,
        public string $name,
        public ?string $code,
        public array $cells,
    ) {}
}
