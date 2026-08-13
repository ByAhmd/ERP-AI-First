<?php

declare(strict_types=1);

namespace Tests\Unit\Calendar;

use App\Support\Calendar\HijriDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Umm al-Qura conversion.
 *
 * Assertions are properties of the calendar rather than hard-coded conversion
 * pairs. ICU is the authority on Umm al-Qura and its tables are revised; pinning
 * specific dates here would encode this codebase's assumptions rather than test
 * the conversion.
 */
final class HijriDateTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function gregorianDates(): array
    {
        return [
            'mid year' => ['2026-07-28'],
            'gregorian new year' => ['2026-01-01'],
            'leap day' => ['2024-02-29'],
            'year end' => ['2025-12-31'],
        ];
    }

    #[Test]
    #[DataProvider('gregorianDates')]
    public function conversion_round_trips(string $gregorian): void
    {
        $date = CarbonImmutable::parse($gregorian, config('erp.timezone'))->startOfDay();

        $parts = HijriDate::parts($date);
        $back = HijriDate::toGregorian($parts['year'], $parts['month'], $parts['day']);

        $this->assertSame(
            $date->toDateString(),
            $back->toDateString(),
            'Gregorian → Hijri → Gregorian should be lossless.',
        );
    }

    #[Test]
    #[DataProvider('gregorianDates')]
    public function parts_are_within_calendar_bounds(string $gregorian): void
    {
        $parts = HijriDate::parts(CarbonImmutable::parse($gregorian, config('erp.timezone')));

        $this->assertGreaterThanOrEqual(1, $parts['month']);
        $this->assertLessThanOrEqual(12, $parts['month']);
        $this->assertGreaterThanOrEqual(1, $parts['day']);
        $this->assertLessThanOrEqual(30, $parts['day']);
        // Sanity bound: the Hijri year for any modern Gregorian date.
        $this->assertGreaterThan(1400, $parts['year']);
    }

    #[Test]
    public function hijri_months_are_twenty_nine_or_thirty_days(): void
    {
        $year = HijriDate::parts(CarbonImmutable::now())['year'];

        for ($month = 1; $month <= 12; $month++) {
            $this->assertContains(
                HijriDate::daysInMonth($year, $month),
                [29, 30],
                "Hijri month {$month} of {$year} had an impossible length.",
            );
        }
    }

    #[Test]
    public function it_formats_in_arabic_with_the_hijri_era_marker(): void
    {
        $formatted = HijriDate::format(CarbonImmutable::parse('2026-07-28'), 'ar');

        $this->assertStringContainsString('هـ', $formatted);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $formatted);
    }

    #[Test]
    public function it_formats_in_english_with_the_ah_era_marker(): void
    {
        $formatted = HijriDate::format(CarbonImmutable::parse('2026-07-28'), 'en');

        $this->assertStringContainsString('AH', $formatted);
    }

    #[Test]
    public function it_rejects_an_impossible_month(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HijriDate::toGregorian(1448, 13, 1);
    }
}
