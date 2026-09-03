<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * One row in a composite drill-down panel.
 */
final readonly class DrillBreakdownRow
{
    public function __construct(
        public string $label,
        public string $signedAmount,
        public int $sign,
        public ?StatementDrillTarget $nestedTarget = null,
    ) {}
}
