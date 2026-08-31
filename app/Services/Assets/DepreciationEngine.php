<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Enums\DocumentStatus;
use App\Enums\FixedAssetStatus;
use App\Models\AccountingPeriod;
use App\Models\DepreciationCharge;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetType;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPoster;
use App\Services\Assets\Exceptions\RunRejected;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Straight-line depreciation — the register's single computing door.
 *
 * Qoyod's confirmed rule, adopted whole: the annual charge is
 * (cost − salvage) × 12 ÷ life-in-months, prorated by day against the
 * calendar year it falls in (365 or 366 — a leap year genuinely adds a
 * day's charge). Where a fiscal period straddles December 31st the day
 * count splits at the boundary and each side uses its own year's rate.
 *
 * Three rules keep the arithmetic honest, all anchored on the POSTED charge
 * rows rather than any recomputation:
 *
 * - The clamp: a period's charge never exceeds what remains of
 *   cost − salvage − posted-so-far. Inside the per-asset arithmetic, never
 *   a UI filter — month 37 of a 36-month life computes to nothing.
 *
 * - The terminal remainder: the period containing the life's last day takes
 *   exactly what remains, so the charges sum to the depreciable base to the
 *   halala by construction.
 *
 * - The idempotency anchor: one charge row per asset per period of record,
 *   enforced by the database. Charge rows are inserted BEFORE the entry
 *   posts, so a concurrent duplicate run dies on the unique index before
 *   any money moves.
 *
 * Catch-up needs no separate path: a run charges every unposted period of
 * record through its target, and a period that closed in the meantime keeps
 * its place as period-of-record while the money lands in the run's open
 * period — both recorded, per charge row.
 */
final class DepreciationEngine
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    /**
     * Run depreciation through a date — normally a period's end, or the
     * disposal date for a disposal catch-up.
     */
    public function run(
        CarbonImmutable $throughDate,
        ?FixedAssetType $type = null,
        ?FixedAsset $only = null,
        ?string $userId = null,
    ): DepreciationRun {
        return DB::transaction(function () use ($throughDate, $type, $only, $userId): DepreciationRun {
            // Loud failures — periodClosed / noOpenPeriod — propagate to the
            // user: create the year or reopen the period. Never redate.
            $postPeriod = $this->calendar->resolveOpenPeriod($throughDate);

            $assets = $this->candidates($type, $only)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $assets->load('type');

            $plans = [];
            $total = '0.0000';
            $count = 0;

            foreach ($assets as $asset) {
                $windows = $this->chargeWindows($asset, $throughDate);

                if ($windows === []) {
                    continue;
                }

                $plans[] = ['asset' => $asset, 'windows' => $windows];
                $count++;

                foreach ($windows as $window) {
                    $total = bcadd($total, $window['amount'], self::SCALE);
                }
            }

            if ($plans === []) {
                throw RunRejected::nothingToPost();
            }

            $run = DepreciationRun::create([
                'reference' => $this->numbers->next(
                    key: 'depreciation_run',
                    defaults: ['prefix' => 'DEP-', 'padding' => 5],
                ),
                'fixed_asset_type_id' => $type?->getKey(),
                'fixed_asset_id' => $only?->getKey(),
                'through_period_id' => $postPeriod->getKey(),
                'through_date' => $throughDate->format('Y-m-d'),
                'status' => DocumentStatus::Approved,
                'total_amount' => $total,
                'assets_count' => $count,
                'created_by_id' => $userId,
            ]);

            // Charges first: the unique (asset, period-of-record) index kills
            // a concurrent duplicate before any money moves.
            foreach ($plans as $plan) {
                foreach ($plan['windows'] as $window) {
                    DepreciationCharge::create([
                        'fixed_asset_id' => $plan['asset']->getKey(),
                        'accounting_period_id' => $window['period']->getKey(),
                        'posted_period_id' => $postPeriod->getKey(),
                        'depreciation_run_id' => $run->getKey(),
                        'amount' => $window['amount'],
                        'days' => $window['days'],
                    ]);
                }
            }

            $entry = $this->poster->post(
                date: $throughDate,
                lines: $this->groupLines($plans),
                description: trim(__('assets.depreciation.narration', [
                    'reference' => $run->reference,
                    'period' => $postPeriod->name,
                ])),
                reference: $run->reference,
                source: $run,
                userId: $userId,
            );

            DepreciationCharge::query()
                ->where('depreciation_run_id', $run->getKey())
                ->update(['journal_entry_id' => $entry->getKey()]);

            $run->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            return $run->refresh();
        });
    }

    /**
     * What a run through a date would charge — display and pre-checks only,
     * no locks taken. Posted figures still come from run().
     *
     * @return array{total: string, assets: list<array{asset: FixedAsset, amount: string, days: int}>}
     */
    public function preview(
        CarbonImmutable $throughDate,
        ?FixedAssetType $type = null,
        ?FixedAsset $only = null,
    ): array {
        $assets = $this->candidates($type, $only)->orderBy('id')->get();
        $assets->load('type');

        $rows = [];
        $total = '0.0000';

        foreach ($assets as $asset) {
            $windows = $this->chargeWindows($asset, $throughDate, tolerateGaps: true);

            if ($windows === []) {
                continue;
            }

            $amount = '0.0000';
            $days = 0;

            foreach ($windows as $window) {
                $amount = bcadd($amount, $window['amount'], self::SCALE);
                $days += $window['days'];
            }

            $rows[] = ['asset' => $asset, 'amount' => $amount, 'days' => $days];
            $total = bcadd($total, $amount, self::SCALE);
        }

        return ['total' => $total, 'assets' => $rows];
    }

    /**
     * The unposted schedule of one asset, projected to its life's end —
     * the display-only forward schedule on the asset view. Stops quietly
     * where accounting periods run out; posted rows remain the only facts.
     *
     * @return list<array{period: AccountingPeriod, amount: string, days: int}>
     */
    public function projection(FixedAsset $asset): array
    {
        if (! $asset->is_depreciable || $asset->useful_life_months === null) {
            return [];
        }

        $lifeEnd = CarbonImmutable::instance($asset->in_service_date)
            ->startOfDay()
            ->addMonthsNoOverflow($asset->useful_life_months)
            ->subDay();

        return $this->chargeWindows($asset, $lifeEnd, tolerateGaps: true);
    }

    /**
     * Reverse an approved run — the module's replacement for a delete.
     *
     * The reversal entry and the charge-row deletion share a fate, so the
     * ledger and the subledger drop together and a future run may
     * legitimately re-claim the periods.
     */
    public function reverse(
        DepreciationRun $run,
        CarbonImmutable $date,
        ?string $userId = null,
    ): DepreciationRun {
        return DB::transaction(function () use ($run, $date, $userId): DepreciationRun {
            $locked = DepreciationRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isApproved()) {
                throw RunRejected::notApproved($locked->reference);
            }

            // A disposal's catch-up is part of the disposal's arithmetic —
            // its snapshots cleared the accumulated this run posted.
            if (FixedAssetDisposal::query()->where('catchup_run_id', $locked->getKey())->exists()) {
                throw RunRejected::boundToDisposal($locked->reference);
            }

            // A disposed asset's snapshots read the posted charges at
            // disposal time; deleting those rows now would falsify them.
            $touchesDisposed = FixedAsset::query()
                ->whereIn('id', DepreciationCharge::query()
                    ->where('depreciation_run_id', $locked->getKey())
                    ->select('fixed_asset_id'))
                ->where('status', '!=', FixedAssetStatus::Active)
                ->exists();

            if ($touchesDisposed) {
                throw RunRejected::hasDisposedAssets($locked->reference);
            }

            $reversal = $this->poster->reverse(
                original: $locked->journalEntry()->firstOrFail(),
                date: $date,
                userId: $userId,
            );

            DepreciationCharge::query()
                ->where('depreciation_run_id', $locked->getKey())
                ->delete();

            $locked->forceFill([
                'status' => DocumentStatus::Void,
                'reversal_journal_entry_id' => $reversal->getKey(),
            ])->save();

            return $locked->refresh();
        });
    }

    // -----------------------------------------------------------------------

    /**
     * @return \Illuminate\Database\Eloquent\Builder<FixedAsset>
     */
    private function candidates(?FixedAssetType $type, ?FixedAsset $only): \Illuminate\Database\Eloquent\Builder
    {
        return FixedAsset::query()
            ->where('status', FixedAssetStatus::Active)
            ->where('is_depreciable', true)
            ->whereNotNull('useful_life_months')
            ->when($type, fn ($q, FixedAssetType $t) => $q->where('fixed_asset_type_id', $t->getKey()))
            ->when($only, fn ($q, FixedAsset $a) => $q->whereKey($a->getKey()));
    }

    /**
     * The unposted charges of one asset, per period of record, through a
     * date — clamped and terminal-corrected against the POSTED sum.
     *
     * @return list<array{period: AccountingPeriod, amount: string, days: int}>
     */
    private function chargeWindows(
        FixedAsset $asset,
        CarbonImmutable $throughDate,
        bool $tolerateGaps = false,
    ): array {
        $life = (int) $asset->useful_life_months;

        // Model date casts hand out MUTABLE Carbon; everything here works on
        // immutables so a chained call can never corrupt a date in place.
        $inService = CarbonImmutable::instance($asset->in_service_date)->startOfDay();

        $start = $inService;

        if ($asset->opening_depreciated_through !== null) {
            $afterOpening = CarbonImmutable::instance($asset->opening_depreciated_through)
                ->startOfDay()
                ->addDay();

            if ($afterOpening->greaterThan($start)) {
                $start = $afterOpening;
            }
        }

        $lifeEnd = $inService->addMonthsNoOverflow($life)->subDay();
        $end = $throughDate->startOfDay()->min($lifeEnd);

        if ($start->greaterThan($end)) {
            return [];
        }

        $base = bcsub((string) $asset->cost, (string) $asset->salvage_value, self::SCALE);
        $accumulated = $asset->accumulatedDepreciation();

        $postedPeriods = $asset->charges()->pluck('accounting_period_id')->all();

        $periods = AccountingPeriod::query()
            ->whereDate('end_date', '>=', $start)
            ->whereDate('start_date', '<=', $end)
            ->orderBy('start_date')
            ->get();

        $annual = BigRational::of((string) $asset->cost)
            ->minus(BigRational::of((string) $asset->salvage_value))
            ->multipliedBy(12)
            ->dividedBy($life);

        $windows = [];
        $cursor = $start;

        foreach ($periods as $period) {
            $periodStart = CarbonImmutable::instance($period->start_date)->startOfDay();
            $periodEnd = CarbonImmutable::instance($period->end_date)->startOfDay();

            // A gap before this period means a fiscal year was never
            // created for part of the range.
            if ($periodStart->greaterThan($cursor)) {
                if ($tolerateGaps) {
                    return $windows;
                }

                throw RunRejected::missingPeriod($cursor->format('Y-m-d'));
            }

            $cursor = $periodEnd->addDay();

            $windowStart = $periodStart->max($start);
            $windowEnd = $periodEnd->min($end);

            if ($windowStart->greaterThan($windowEnd)) {
                continue;
            }

            if (in_array($period->getKey(), $postedPeriods, true)) {
                continue;
            }

            $remaining = bcsub($base, $accumulated, self::SCALE);

            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                break;
            }

            [$amount, $days] = $this->windowCharge($annual, $windowStart, $windowEnd);

            // Terminal remainder: the window reaching the life's last day
            // takes exactly what is left. The clamp covers everything else.
            $isTerminal = $windowEnd->equalTo($lifeEnd);

            if ($isTerminal || bccomp($amount, $remaining, self::SCALE) > 0) {
                $amount = $remaining;
            }

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                continue;
            }

            $windows[] = ['period' => $period, 'amount' => $amount, 'days' => $days];
            $accumulated = bcadd($accumulated, $amount, self::SCALE);
        }

        if (! $tolerateGaps && $cursor->lessThanOrEqualTo($end)) {
            throw RunRejected::missingPeriod($cursor->format('Y-m-d'));
        }

        return $windows;
    }

    /**
     * Day-count a window, splitting at calendar-year boundaries so each
     * side takes its own year's daily rate.
     *
     * @return array{0: string, 1: int}
     */
    private function windowCharge(BigRational $annual, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $charge = BigRational::of(0);
        $days = 0;
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            $segmentEnd = $cursor->endOfYear()->startOfDay()->min($to);
            $segmentDays = (int) $cursor->diffInDays($segmentEnd) + 1;

            $charge = $charge->plus(
                $annual->dividedBy($cursor->isLeapYear() ? 366 : 365)
                    ->multipliedBy($segmentDays),
            );

            $days += $segmentDays;
            $cursor = $segmentEnd->addDay();
        }

        $amount = bcadd(
            (string) $charge->toScale(2, RoundingMode::HalfUp),
            '0',
            self::SCALE,
        );

        return [$amount, $days];
    }

    /**
     * One DR/CR pair per (expense account, accumulated account, branch).
     *
     * @param  list<array{asset: FixedAsset, windows: list<array{period: AccountingPeriod, amount: string, days: int}>}>  $plans
     * @return list<JournalLineData>
     */
    private function groupLines(array $plans): array
    {
        /** @var array<string, array{expense: string, accumulated: string, branch: ?string, amount: string}> $groups */
        $groups = [];

        foreach ($plans as $plan) {
            $type = $plan['asset']->type;
            $branch = $plan['asset']->branch_id;

            $key = $type->depreciation_expense_account_id.'|'
                .$type->accumulated_depreciation_account_id.'|'
                .($branch ?? '');

            $amount = '0.0000';

            foreach ($plan['windows'] as $window) {
                $amount = bcadd($amount, $window['amount'], self::SCALE);
            }

            $groups[$key] = [
                'expense' => (string) $type->depreciation_expense_account_id,
                'accumulated' => (string) $type->accumulated_depreciation_account_id,
                'branch' => $branch,
                'amount' => bcadd($groups[$key]['amount'] ?? '0', $amount, self::SCALE),
            ];
        }

        $lines = [];

        foreach ($groups as $group) {
            $lines[] = JournalLineData::debit($group['expense'], $group['amount'])
                ->withBranch($group['branch']);
            $lines[] = JournalLineData::credit($group['accumulated'], $group['amount'])
                ->withBranch($group['branch']);
        }

        return $lines;
    }
}
