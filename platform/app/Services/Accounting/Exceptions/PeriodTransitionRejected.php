<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Models\FiscalYear;
use RuntimeException;

/**
 * A fiscal year state change that would leave the calendar inconsistent.
 */
final class PeriodTransitionRejected extends RuntimeException
{
    public static function notCloseable(FiscalYear $year): self
    {
        return new self(__('accounting.errors.year_not_closeable', [
            'year' => $year->name,
            'status' => $year->status->getLabel(),
        ]));
    }

    public static function notReopenable(FiscalYear $year): self
    {
        return new self(__('accounting.errors.year_not_reopenable', [
            'year' => $year->name,
            'status' => $year->status->getLabel(),
        ]));
    }
}
