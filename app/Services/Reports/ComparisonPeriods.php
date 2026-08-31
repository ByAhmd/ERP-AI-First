<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * The column dates of an aging report.
 *
 * Qoyod's four aging reports are not day-bucket reports: they are as-of
 * snapshots, optionally recomputed at the ends of prior calendar periods and
 * shown side by side — مقارنة بـ picks the period length, عدد فترات المقارنة
 * picks how many extra columns appear, up to thirteen. This class turns that
 * pair into the list of dates the report computes at.
 *
 * Calendar periods, not fiscal ones: a "prior year" column ends December 31
 * whatever the fiscal year says. The week ends Saturday — the Saudi week.
 */
final class ComparisonPeriods
{
    public const UNITS = ['year', 'quarter', 'month', 'week'];

    public const MAX_PERIODS = 13;

    /**
     * The as-of date followed by the ends of the prior periods, newest first.
     *
     * @return list<CarbonImmutable>
     */
    public static function dates(CarbonImmutable $asOf, ?string $unit, int $periods): array
    {
        $dates = [$asOf];

        if ($unit === null || ! in_array($unit, self::UNITS, true)) {
            return $dates;
        }

        $periods = max(1, min(self::MAX_PERIODS, $periods));

        for ($k = 1; $k <= $periods; $k++) {
            $dates[] = match ($unit) {
                'year' => $asOf->subYearsNoOverflow($k)->endOfYear(),
                'quarter' => $asOf->subQuartersNoOverflow($k)->endOfQuarter(),
                'month' => $asOf->subMonthsNoOverflow($k)->endOfMonth(),
                'week' => $asOf->subWeeks($k)->endOfWeek(CarbonImmutable::SATURDAY),
            };
        }

        return $dates;
    }
}
