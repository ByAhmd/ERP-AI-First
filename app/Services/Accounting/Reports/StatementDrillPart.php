<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * One signed contributor to a composite figure.
 */
final readonly class StatementDrillPart
{
    public function __construct(
        public string $label,
        public int $sign,
        public StatementDrillReference $reference,
    ) {}
}
