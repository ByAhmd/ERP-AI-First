<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Services\Accounting\Exceptions\PostingRejected;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resolves dates to accounting periods, and builds the calendar.
 *
 * Every posting passes through {@see resolveOpenPeriod()}, which is the single
 * gate deciding whether a date may still be written to.
 */
final class FiscalCalendar
{
    /**
     * The period a date belongs to, if it is open for posting.
     *
     * Distinguishes "no period exists" from "the period is closed", because the
     * two need different action from the user: create the year, or reopen it.
     */
    public function resolveOpenPeriod(DateTimeInterface $date): AccountingPeriod
    {
        $period = $this->findPeriod($date);

        if ($period === null) {
            throw PostingRejected::noOpenPeriod($date);
        }

        if (! $period->acceptsPostings()) {
            throw PostingRejected::periodClosed($date);
        }

        return $period;
    }

    public function findPeriod(DateTimeInterface $date): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->with('fiscalYear')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    public function findYear(DateTimeInterface $date): ?FiscalYear
    {
        return FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    /**
     * Create a fiscal year and its twelve monthly periods.
     *
     * The year begins on the company's configured fiscal start, which is
     * frequently not January — Saudi companies commonly align to a licence or
     * Zakat year rather than the Gregorian one.
     */
    public function createYear(Company $company, int $startingYear, ?string $name = null): FiscalYear
    {
        return DB::transaction(function () use ($company, $startingYear, $name): FiscalYear {
            $start = CarbonImmutable::create(
                $startingYear,
                $company->fiscal_year_start_month,
                $company->fiscal_year_start_day,
            )->startOfDay();

            $end = $start->addYear()->subDay()->endOfDay();

            $year = FiscalYear::create([
                'company_id' => $company->getKey(),
                'name' => $name ?? $this->deriveYearName($start, $end),
                'start_date' => $start,
                'end_date' => $end,
                'status' => PeriodStatus::Open,
            ]);

            $this->createMonthlyPeriods($year, $start);

            return $year->refresh();
        });
    }

    /**
     * Twelve periods, each running to the day before the next begins.
     *
     * Derived by month arithmetic rather than by adding fixed day counts, so
     * month lengths and leap years take care of themselves.
     */
    private function createMonthlyPeriods(FiscalYear $year, CarbonImmutable $start): void
    {
        for ($index = 0; $index < 12; $index++) {
            $periodStart = $start->addMonths($index);
            $periodEnd = $start->addMonths($index + 1)->subDay()->endOfDay();

            AccountingPeriod::create([
                'company_id' => $year->company_id,
                'fiscal_year_id' => $year->getKey(),
                'name' => $periodStart->translatedFormat('F Y'),
                'sequence' => $index + 1,
                'start_date' => $periodStart,
                'end_date' => $periodEnd,
                'status' => PeriodStatus::Open,
            ]);
        }
    }

    /**
     * A year spanning one calendar year is named for it; one that straddles two
     * is named for both, since "2026" would be ambiguous.
     */
    private function deriveYearName(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->year === $end->year
            ? (string) $start->year
            : "{$start->year}/{$end->year}";
    }
}
