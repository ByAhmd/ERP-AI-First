<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\Accounting\Exceptions\PeriodTransitionRejected;
use App\Services\Accounting\Reports\BalanceSheet;
use Illuminate\Support\Facades\DB;

/**
 * Opens and seals fiscal years.
 *
 * Closing a year is two coordinated writes — the year itself and every period
 * inside it — and they must succeed or fail together. Performed separately, a
 * failure between them leaves a closed year sitting above open periods, and the
 * two disagree about whether the date range still accepts postings.
 *
 * Previously this ran inside the Filament table that displays the button, which
 * put a business rule in a view-configuration class and gave it no transaction.
 */
final class FiscalYearCloser
{
    /**
     * Seal a year and every period within it.
     */
    public function close(FiscalYear $year, ?string $userId = null): FiscalYear
    {
        if ($year->status !== PeriodStatus::Open && $year->status !== PeriodStatus::Adjusting) {
            throw PeriodTransitionRejected::notCloseable($year);
        }

        return DB::transaction(function () use ($year, $userId): FiscalYear {
            $year->forceFill([
                'status' => PeriodStatus::Closed,
                'closed_at' => now(),
                'closed_by_id' => $userId,
            ])->save();

            // An open period beneath a closed year is contradictory, and the
            // posting gate reads both.
            AccountingPeriod::query()
                ->where('fiscal_year_id', $year->getKey())
                ->update([
                    'status' => PeriodStatus::Closed->value,
                    'closed_at' => now(),
                    'closed_by_id' => $userId,
                ]);

            return $year->refresh();
        });
    }

    /**
     * Reopen a closed year and its periods.
     *
     * A locked year cannot be reopened. Locking is the point at which the
     * year's figures have been reported outward — to ZATCA, to an auditor, to
     * an owner — and reopening would let a filed period be restated behind
     * those reports. Correction after locking is by adjusting entry in the year
     * that follows.
     *
     * Note that closing moves no balances. Revenue and expense accounts keep
     * their figures and nothing posts to retained earnings, so the result for
     * a period is derived where it is needed rather than stored; see
     * {@see BalanceSheet} for why that holds
     * whether or not closing entries are introduced later.
     */
    public function reopen(FiscalYear $year, ?string $userId = null): FiscalYear
    {
        if (! $year->status->canReopen()) {
            throw PeriodTransitionRejected::notReopenable($year);
        }

        return DB::transaction(function () use ($year): FiscalYear {
            $year->forceFill([
                'status' => PeriodStatus::Open,
                'closed_at' => null,
                'closed_by_id' => null,
            ])->save();

            AccountingPeriod::query()
                ->where('fiscal_year_id', $year->getKey())
                ->update([
                    'status' => PeriodStatus::Open->value,
                    'closed_at' => null,
                    'closed_by_id' => null,
                ]);

            return $year->refresh();
        });
    }
}
