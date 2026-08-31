<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * One contact's line on the summary view of the debt aging report.
 *
 * @property array<string, string> $buckets bucket key => amount
 */
final readonly class DebtAgingSummaryRow
{
    /**
     * @param  array<string, string>  $buckets
     */
    public function __construct(
        public string $contactId,
        public string $name,
        public string $type,
        public array $buckets,
        public string $total,
    ) {}
}
