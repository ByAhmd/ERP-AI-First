<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Enums\DocumentStatus;
use App\Enums\PeriodStatus;
use App\Models\Branch;
use App\Models\DepreciationCharge;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\StockAdjustment;
use App\Services\Accounting\SubledgerSourceTypes;
use App\Services\Assets\DepreciationEngine;
use App\Services\Assets\Exceptions\RunRejected;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * The straight-line engine's arithmetic invariants.
 *
 * The standard asset: cost 10,000, salvage 1,000, life 3 months, in service
 * 2026-01-01. Annual charge 36,000; daily 36,000/365. January is 3057.53,
 * February 2761.64, and March takes the exact remainder 3180.83 — the three
 * sum to the depreciable base 9,000.00 to the halala by construction.
 */
final class DepreciationEngineTest extends AssetTestCase
{
    #[Test]
    public function charges_follow_the_day_count_and_salvage_reduces_the_base(): void
    {
        $asset = $this->registerAsset();

        $run = $this->runThrough('2026-01-31', $asset);

        $charge = DepreciationCharge::query()->sole();

        // 31 days at (10,000 − 1,000) × 12 ÷ 3 ÷ 365 — the salvage is out of
        // the base, or this would read 3397.26.
        $this->assertSame(0, bccomp('3057.53', (string) $charge->amount, 4));
        $this->assertSame(31, $charge->days);
        $this->assertSame($this->periodContaining('2026-01-15')->getKey(), $charge->accounting_period_id);
        $this->assertSame($this->periodContaining('2026-01-15')->getKey(), $charge->posted_period_id);

        $this->assertSame(0, bccomp('3057.53', $this->balanceOf($this->accountByKey('depreciation_expense')), 4));
        $this->assertSame(0, bccomp('-3057.53', $this->balanceOf($this->accountByKey('accumulated_depreciation')), 4));

        $this->assertSame(0, bccomp('3057.53', (string) $run->total_amount, 4));
        $this->assertSame(1, $run->assets_count);
        $this->assertNotNull($run->journal_entry_id);
        $this->assertSame($run->journal_entry_id, $charge->journal_entry_id);
    }

    #[Test]
    public function the_terminal_charge_takes_the_exact_remainder_and_charges_stop_at_the_base(): void
    {
        $asset = $this->registerAsset();

        // Through June: the life ended March 31st, so exactly three charges
        // exist and they sum to the base with no orphan halalas.
        $this->runThrough('2026-06-30', $asset);

        $amounts = DepreciationCharge::query()->orderBy('id')->pluck('amount')->all();

        $this->assertCount(3, $amounts);
        $this->assertSame(0, bccomp('3057.53', (string) $amounts[0], 4));
        $this->assertSame(0, bccomp('2761.64', (string) $amounts[1], 4));
        $this->assertSame(0, bccomp('3180.83', (string) $amounts[2], 4));

        $this->assertSame(0, bccomp('9000.0000', $asset->refresh()->accumulatedDepreciation(), 4));
        $this->assertSame(0, bccomp('1000.0000', $asset->bookValue(), 4));

        // Month 4 onward computes to nothing — the clamp, not a UI filter.
        try {
            $this->runThrough('2026-07-31', $asset);
            $this->fail('A fully depreciated asset accepted another run.');
        } catch (RunRejected) {
        }

        $this->assertSame(0, bccomp('9000.0000', $this->balanceOf($this->accountByKey('depreciation_expense')), 4));
    }

    #[Test]
    public function rerunning_a_posted_period_posts_nothing(): void
    {
        $asset = $this->registerAsset();

        $this->runThrough('2026-01-31', $asset);

        try {
            $this->runThrough('2026-01-31', $asset);
            $this->fail('The same period was charged twice.');
        } catch (RunRejected) {
        }

        $this->assertSame(1, DepreciationCharge::query()->count());
        $this->assertSame(0, bccomp('3057.53', $this->balanceOf($this->accountByKey('depreciation_expense')), 4));
    }

    #[Test]
    public function catchup_records_the_period_of_record_and_the_period_posted_into(): void
    {
        $asset = $this->registerAsset();

        $this->runThrough('2026-01-31', $asset);

        // February closes before anyone ran it.
        $february = $this->periodContaining('2026-02-15');
        $february->forceFill(['status' => PeriodStatus::Closed])->save();

        $run = $this->runThrough('2026-03-31', $asset);

        $march = $this->periodContaining('2026-03-15');

        $februaryCharge = DepreciationCharge::query()
            ->where('accounting_period_id', $february->getKey())
            ->sole();

        // The period of record stays February; the money landed in March.
        $this->assertSame($march->getKey(), $februaryCharge->posted_period_id);
        $this->assertSame(0, bccomp('2761.64', (string) $februaryCharge->amount, 4));

        $marchCharge = DepreciationCharge::query()
            ->where('accounting_period_id', $march->getKey())
            ->sole();

        $this->assertSame($march->getKey(), $marchCharge->posted_period_id);

        $entry = $run->journalEntry()->firstOrFail();
        $this->assertSame('2026-03-31', $entry->entry_date->format('Y-m-d'));
    }

    #[Test]
    public function a_run_needing_a_missing_record_period_fails_loudly(): void
    {
        // In service before any created fiscal year: the December 2025
        // period of record does not exist, and silence here would misdate
        // the charge.
        $asset = $this->registerAsset([
            'in_service_date' => '2025-12-01',
            'acquisition_date' => '2025-12-01',
            'useful_life_months' => 6,
        ]);

        try {
            $this->runThrough('2026-01-31', $asset);
            $this->fail('A missing record period went unnoticed.');
        } catch (RunRejected) {
        }

        $this->assertSame(0, DepreciationCharge::query()->count());
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByKey('depreciation_expense')), 4));
    }

    #[Test]
    public function run_reversal_removes_charges_and_gl_together_and_a_rerun_reclaims(): void
    {
        $asset = $this->registerAsset();

        $run = $this->runThrough('2026-01-31', $asset);

        app(DepreciationEngine::class)->reverse($run, CarbonImmutable::parse('2026-01-31'));

        $this->assertSame(0, DepreciationCharge::query()->count());
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByKey('depreciation_expense')), 4));
        $this->assertSame(DocumentStatus::Void, $run->refresh()->status);
        $this->assertNotNull($run->reversal_journal_entry_id);

        // The periods are legitimately free again.
        $again = $this->runThrough('2026-01-31', $asset);

        $this->assertSame(0, bccomp('3057.53', (string) $again->total_amount, 4));
        $this->assertSame(0, bccomp('3057.53', $this->balanceOf($this->accountByKey('depreciation_expense')), 4));
    }

    #[Test]
    public function every_depreciation_line_carries_the_asset_branch(): void
    {
        $second = Branch::create(['code' => 'B2', 'name' => 'فرع جدة']);

        $asset = $this->registerAsset(['branch_id' => $second->getKey()]);

        $run = $this->runThrough('2026-01-31', $asset);

        $lines = $run->journalEntry()->firstOrFail()->lines()->get();

        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $this->assertSame($second->getKey(), $line->branch_id);
        }
    }

    #[Test]
    public function the_mid_month_start_prorates_by_day(): void
    {
        // The decision document's worked check: in service June 15th, cost
        // 10,000, salvage 1,000, life 36 → annual 3,000; June carries 16
        // days at 3,000/365 = 131.51.
        $asset = $this->registerAsset([
            'in_service_date' => '2026-06-15',
            'acquisition_date' => '2026-06-15',
            'useful_life_months' => 36,
        ]);

        $run = $this->runThrough('2026-06-30', $asset);

        $charge = DepreciationCharge::query()->sole();

        $this->assertSame(0, bccomp('131.51', (string) $charge->amount, 4));
        $this->assertSame(16, $charge->days);
        $this->assertSame(0, bccomp('131.51', (string) $run->total_amount, 4));
    }

    #[Test]
    public function the_ledger_screen_reverse_is_blocked_for_asset_sources(): void
    {
        $this->assertTrue(SubledgerSourceTypes::contains(DepreciationRun::class));
        $this->assertTrue(SubledgerSourceTypes::contains(FixedAssetDisposal::class));
        $this->assertTrue(SubledgerSourceTypes::contains(FixedAsset::class));

        // The inventory list still rides along — one union, no second list.
        $this->assertTrue(SubledgerSourceTypes::contains(StockAdjustment::class));

        $this->assertFalse(SubledgerSourceTypes::contains(null));
        $this->assertFalse(SubledgerSourceTypes::contains(\App\Models\JournalEntry::class));
    }
}
