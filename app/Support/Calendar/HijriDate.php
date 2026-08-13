<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use IntlCalendar;
use IntlDateFormatter;
use InvalidArgumentException;

/**
 * Conversion between the Gregorian and Umm al-Qura calendars.
 *
 * Umm al-Qura is the civil calendar of Saudi Arabia and the basis for Zakat
 * assessment periods. It is provided by ICU through PHP's intl extension, so it
 * stays correct as ICU is updated — unlike the arithmetic approximations used by
 * most PHP Hijri packages, which drift from the published Umm al-Qura tables.
 *
 * Gregorian remains the storage format everywhere. Hijri is a presentation and
 * period-definition concern only; storing it would make date arithmetic and
 * range queries needlessly hard.
 */
final class HijriDate
{
    private const CALENDAR = 'islamic-umalqura';

    /**
     * Format a Gregorian instant as an Umm al-Qura date string.
     *
     * @param  'ar'|'en'  $locale
     */
    public static function format(
        DateTimeInterface $date,
        string $locale = 'ar',
        int $dateStyle = IntlDateFormatter::LONG,
    ): string {
        $formatter = new IntlDateFormatter(
            self::localeTag($locale),
            $dateStyle,
            IntlDateFormatter::NONE,
            self::timezone(),
            IntlDateFormatter::TRADITIONAL,
        );

        $formatted = $formatter->format($date);

        if ($formatted === false) {
            throw new InvalidArgumentException('The given date could not be formatted as Umm al-Qura.');
        }

        return $formatted;
    }

    /**
     * The Hijri year, month and day for a Gregorian instant.
     *
     * @return array{year: int, month: int, day: int}
     */
    public static function parts(DateTimeInterface $date): array
    {
        $calendar = self::calendar();
        $calendar->setTime($date->getTimestamp() * 1000);

        return [
            'year' => $calendar->get(IntlCalendar::FIELD_YEAR),
            // ICU months are zero-based; callers expect 1-12.
            'month' => $calendar->get(IntlCalendar::FIELD_MONTH) + 1,
            'day' => $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /**
     * The Gregorian instant on which a given Umm al-Qura date begins.
     */
    public static function toGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Hijri month must be between 1 and 12, {$month} given.");
        }

        $calendar = self::calendar();
        $calendar->clear();
        $calendar->set($year, $month - 1, $day);

        return CarbonImmutable::createFromTimestampMs(
            $calendar->getTime(),
            self::timezone(),
        )->startOfDay();
    }

    /**
     * Number of days in a given Umm al-Qura month.
     *
     * Hijri months are 29 or 30 days and the pattern is not cyclical, so this
     * must be asked of ICU rather than calculated.
     */
    public static function daysInMonth(int $year, int $month): int
    {
        $calendar = self::calendar();
        $calendar->clear();
        $calendar->set($year, $month - 1, 1);

        return $calendar->getActualMaximum(IntlCalendar::FIELD_DAY_OF_MONTH);
    }

    private static function calendar(): IntlCalendar
    {
        // createInstance() is typed as returning IntlCalendar and cannot return
        // null, so neither an instanceof guard nor a null coalesce adds
        // anything. A missing intl extension fails earlier, at class load.
        return IntlCalendar::createInstance(self::timezone(), self::localeTag('en'));
    }

    private static function localeTag(string $locale): string
    {
        return $locale.'@calendar='.config('erp.hijri_calendar', self::CALENDAR);
    }

    private static function timezone(): string
    {
        return config('erp.timezone', 'Asia/Riyadh');
    }
}
