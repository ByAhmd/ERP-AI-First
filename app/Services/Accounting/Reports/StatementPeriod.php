<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\ComparisonInterval;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One column of a financial statement.
 *
 * Carries both the window it covers and the heading a reader sees, because the
 * two must agree and deriving the label anywhere other than beside the range
 * invites a column headed March that reports February.
 */
final readonly class StatementPeriod
{
    public function __construct(
        public string $label,
        public DateRange $range,
    ) {}

    /**
     * Columns for a point-in-time statement.
     *
     * Each is a balance accumulated from inception to its own date, so the
     * ranges are nested rather than adjacent — an earlier column is a prefix of
     * a later one, not a slice beside it.
     *
     * @return list<StatementPeriod>
     */
    public static function asOf(
        DateTimeInterface $date,
        ComparisonInterval $interval,
        int $comparisons,
    ): array {
        $base = CarbonImmutable::instance($date);
        $periods = [];

        foreach (self::steps($interval, $comparisons) as $step) {
            $end = $interval->shiftBack($base, $step);

            $periods[] = new self(
                label: self::formatDate($end),
                range: DateRange::upTo($end),
            );
        }

        return $periods;
    }

    /**
     * Columns for a period statement.
     *
     * The window keeps its shape and slides back whole intervals at a time, so
     * a month compared against a month stays a month rather than becoming
     * whatever number of days separates the two.
     *
     * @return list<StatementPeriod>
     */
    public static function between(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ComparisonInterval $interval,
        int $comparisons,
    ): array {
        $start = CarbonImmutable::instance($from);
        $end = CarbonImmutable::instance($to);
        $periods = [];

        foreach (self::steps($interval, $comparisons) as $step) {
            $shiftedStart = $interval->shiftBack($start, $step);
            $shiftedEnd = $interval->shiftBack($end, $step);

            $periods[] = new self(
                label: self::formatRange($shiftedStart, $shiftedEnd),
                range: DateRange::between($shiftedStart, $shiftedEnd),
            );
        }

        return $periods;
    }

    /**
     * How many steps back each column sits, current first.
     *
     * @return list<int>
     */
    private static function steps(ComparisonInterval $interval, int $comparisons): array
    {
        $count = max(0, min($comparisons, $interval->maximumComparisons()));

        return range(0, $count);
    }

    private static function formatDate(CarbonImmutable $date): string
    {
        return $date->translatedFormat('d M Y');
    }

    private static function formatRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        // A period inside one month reads better as "1 – 31 August 2026" than
        // as the same month and year printed twice.
        if ($start->isSameMonth($end)) {
            return $start->translatedFormat('j').' – '.$end->translatedFormat('j M Y');
        }

        return $start->translatedFormat('j M Y').' – '.$end->translatedFormat('j M Y');
    }
}
