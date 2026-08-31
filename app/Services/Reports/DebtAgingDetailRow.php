<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * One document's line on the details view of the debt aging report.
 *
 * The delay is signed, Qoyod's أيام التأخير: positive means overdue by that
 * many days, negative means not yet due for that many.
 */
final readonly class DebtAgingDetailRow
{
    public function __construct(
        public string $reference,
        public string $documentType,
        public string $issueDate,
        public ?string $dueDate,
        public string $contactName,
        public string $remainder,
        public int $delayDays,
    ) {}
}
