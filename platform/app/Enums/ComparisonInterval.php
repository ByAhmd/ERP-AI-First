<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;

/**
 * The step between comparison columns on a financial statement.
 *
 * A statement is far more useful beside its own history — a cost that grew by a
 * third is invisible in a single column and obvious in three. Qoyod offers the
 * same four steps, which is the vocabulary Saudi accountants already have for
 * this, so the platform does not invent a fifth.
 */
enum ComparisonInterval: string implements HasLabel
{
    case None = 'none';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    public function getLabel(): string
    {
        return __("accounting.comparison.interval.{$this->value}");
    }

    /**
     * Step a date back by this interval, repeated.
     *
     * The no-overflow variants are deliberate. Carbon's plain subMonths() maps
     * the 31st onto a short month by rolling into the next one, so comparing
     * March against February would silently report a column ending 3 March.
     */
    public function shiftBack(CarbonImmutable $date, int $times): CarbonImmutable
    {
        if ($times === 0) {
            return $date;
        }

        return match ($this) {
            self::None => $date,
            self::Week => $date->subWeeks($times),
            self::Month => $date->subMonthsNoOverflow($times),
            self::Quarter => $date->subMonthsNoOverflow(3 * $times),
            self::Year => $date->subYearsNoOverflow($times),
        };
    }

    /**
     * The most columns this interval may add beside the current one.
     *
     * Thirteen matches Qoyod, and is not arbitrary: thirteen monthly columns
     * covers a year plus the same month last year, which is the comparison
     * seasonal businesses actually want.
     */
    public function maximumComparisons(): int
    {
        return $this === self::None ? 0 : 13;
    }
}
