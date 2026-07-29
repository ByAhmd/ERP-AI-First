<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Services\Accounting\FiscalCalendar;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fiscal year and period construction.
 *
 * Saudi companies commonly align the financial year to a licence or Zakat year
 * rather than to January, so a start date on any month and day has to produce a
 * sound calendar.
 */
final class FiscalCalendarTest extends TestCase
{
    use RefreshDatabase;

    private FiscalCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = app(FiscalCalendar::class);
    }

    #[Test]
    public function a_new_company_defaults_to_a_january_start(): void
    {
        // Regression: these columns are defaulted by the database, so a
        // freshly created model carried no value for them and date
        // construction silently fell back to today's month and day.
        $company = Company::create(['name' => 'Acme Trading']);

        $this->assertSame(1, $company->fiscal_year_start_month);
        $this->assertSame(1, $company->fiscal_year_start_day);
        $this->assertSame('SAR', $company->base_currency);
    }

    #[Test]
    public function it_builds_twelve_periods_covering_the_whole_year(): void
    {
        $company = $this->company(1, 1);
        $year = $this->calendar->createYear($company, 2026);

        $this->assertCount(12, $year->periods);
        $this->assertSame('2026-01-01', $year->start_date->toDateString());
        $this->assertSame('2026-12-31', $year->end_date->toDateString());
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function fiscalStarts(): array
    {
        return [
            'january' => [1, 1],
            'april (common licence year)' => [4, 1],
            'july' => [7, 1],
            // The cases that broke: adding months to a late-month anchor
            // overflows into the month after next.
            'day 29' => [1, 29],
            'day 30' => [6, 30],
            'day 31' => [3, 31],
        ];
    }

    #[Test]
    #[DataProvider('fiscalStarts')]
    public function periods_never_overlap_or_leave_gaps(int $month, int $day): void
    {
        $company = $this->company($month, $day);
        $year = $this->calendar->createYear($company, 2026);

        $periods = $year->periods()->orderBy('sequence')->get();

        $this->assertCount(12, $periods);

        // Each period must begin the day after the previous one ends.
        foreach ($periods as $index => $period) {
            if ($index === 0) {
                $this->assertSame(
                    $year->start_date->toDateString(),
                    $period->start_date->toDateString(),
                );

                continue;
            }

            $this->assertSame(
                $periods[$index - 1]->end_date->addDay()->toDateString(),
                $period->start_date->toDateString(),
                'Periods must be contiguous.',
            );
        }

        $this->assertSame(
            $year->end_date->toDateString(),
            $periods->last()->end_date->toDateString(),
            'The last period must end with the year.',
        );
    }

    #[Test]
    #[DataProvider('fiscalStarts')]
    public function every_period_has_a_distinct_name(int $month, int $day): void
    {
        // A duplicate name violates the unique index and rejects the whole year.
        $company = $this->company($month, $day);
        $year = $this->calendar->createYear($company, 2026);

        $names = $year->periods()->pluck('name');

        $this->assertSame($names->count(), $names->unique()->count());
    }

    #[Test]
    public function every_day_of_the_year_resolves_to_exactly_one_period(): void
    {
        $company = $this->company(3, 31);
        $year = $this->calendar->createYear($company, 2026);

        app(CompanyContext::class)->set($company);

        $cursor = CarbonImmutable::parse($year->start_date->toDateString());
        $end = CarbonImmutable::parse($year->end_date->toDateString());

        while ($cursor <= $end) {
            $matches = AccountingPeriod::query()
                ->whereDate('start_date', '<=', $cursor)
                ->whereDate('end_date', '>=', $cursor)
                ->count();

            $this->assertSame(
                1,
                $matches,
                "Date {$cursor->toDateString()} resolved to {$matches} periods.",
            );

            $cursor = $cursor->addDay();
        }
    }

    #[Test]
    public function a_year_spanning_two_calendar_years_is_named_for_both(): void
    {
        $company = $this->company(4, 1);
        $year = $this->calendar->createYear($company, 2026);

        // "2026" alone would be ambiguous for a year running into 2027.
        $this->assertSame('2026/2027', $year->name);
    }

    private function company(int $month, int $day): Company
    {
        $company = Company::create([
            'name' => "Acme {$month}-{$day}",
            'fiscal_year_start_month' => $month,
            'fiscal_year_start_day' => $day,
        ]);

        app(CompanyContext::class)->set($company);

        return $company;
    }
}
