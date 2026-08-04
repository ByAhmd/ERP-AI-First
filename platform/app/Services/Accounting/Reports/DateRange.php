<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A window of ledger dates, closed at the end and optionally open at the start.
 *
 * An open start means "since the company began" — the form every balance-sheet
 * figure takes, because a balance is the accumulation of everything posted up
 * to a date rather than the movement across a period. That is the whole
 * difference between the two statements, and modelling it here keeps it from
 * being re-expressed, slightly differently, in each report.
 *
 * Both ends are inclusive dates rather than instants. Ledger queries compare on
 * date alone, so an exclusive end is expressed by stepping back a day at
 * construction instead of carrying a flag that every caller must remember to
 * pass. There is then no way to ask for the wrong one.
 */
final readonly class DateRange
{
    private function __construct(
        public ?CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function between(DateTimeInterface $start, DateTimeInterface $end): self
    {
        return new self(CarbonImmutable::instance($start), CarbonImmutable::instance($end));
    }

    /**
     * Everything posted on or before a date.
     */
    public static function upTo(DateTimeInterface $end): self
    {
        return new self(null, CarbonImmutable::instance($end));
    }

    /**
     * Everything posted strictly before a date — an opening balance.
     */
    public static function endingBefore(DateTimeInterface $date): self
    {
        return new self(null, CarbonImmutable::instance($date)->subDay());
    }

    /**
     * Whether the window can contain anything at all.
     *
     * A caller asking for the period before the first day of trading produces
     * an end that precedes the start; the query would return nothing anyway,
     * but saying so here lets a report skip the round trip.
     */
    public function isEmpty(): bool
    {
        return $this->start !== null && $this->start->startOfDay()->greaterThan($this->end->startOfDay());
    }
}
