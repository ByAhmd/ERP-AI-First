<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Enums\AssetDisposalKind;
use App\Enums\PeriodStatus;
use App\Models\DepreciationCharge;
use App\Models\FixedAssetDisposal;
use App\Models\Tax;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\JournalPoster;
use App\Services\Assets\AssetDisposalPoster;
use App\Services\Assets\DepreciationEngine;
use App\Services\Assets\Exceptions\RunRejected;
use App\Services\Assets\Reports\FixedAssetsTie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The closing test: the register ties to the control accounts through the
 * whole life the slice supports — openings both ways, a manual purchase,
 * runs, an idempotent refusal, a catch-up across a closed period, a
 * reversal with its re-run, and both disposals — to the fourth decimal.
 */
final class AssetPostingTest extends AssetTestCase
{
    #[Test]
    public function the_asset_register_ties_to_the_control_accounts(): void
    {
        $tax = Tax::query()->where('is_default', true)->firstOrFail();

        // A life before the register: 1210 already carries an asset.
        app(JournalPoster::class)->post(
            CarbonImmutable::parse('2026-01-01'),
            [
                JournalLineData::debit($this->accountByCode('1210')->getKey(), '20000'),
                JournalLineData::credit($this->accountByCode('3900')->getKey(), '20000'),
            ],
            description: 'رصيد قائم قبل السجل',
        );

        // The bridge: registered against the existing balance, no posting.
        $bridged = $this->registerAsset([
            'name' => 'مبنى المستودع',
            'cost' => '20000',
            'salvage_value' => '0',
            'useful_life_months' => 24,
            'register_only' => true,
        ]);

        // An opening not yet in the GL, partly depreciated elsewhere. The
        // acquisition date is the BOOKING date of the opening entry —
        // Qoyod's التاريخ — while the in-service date keeps the history.
        $opened = $this->registerAsset([
            'name' => 'خط الإنتاج',
            'acquisition_date' => '2026-01-01',
            'in_service_date' => '2025-01-01',
            'cost' => '12000',
            'salvage_value' => '0',
            'useful_life_months' => 36,
            'register_only' => false,
            'opening_accumulated_depreciation' => '2000',
            'opening_depreciated_through' => '2025-12-31',
        ]);

        // A manual purchase with recoverable VAT.
        $bought = $this->registerAsset([
            'name' => 'رافعة شوكية',
            'acquisition_kind' => 'purchase',
            'acquisition_date' => '2026-02-01',
            'in_service_date' => '2026-02-01',
            'cost' => '8000',
            'salvage_value' => '800',
            'useful_life_months' => 12,
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
            'tax_id' => $tax->getKey(),
        ]);

        $tie = app(FixedAssetsTie::class)->build();
        $this->assertTrue($tie['balanced'], 'The tie broke at registration.');

        $engine = app(DepreciationEngine::class);

        // Through February, everyone: the bridged and opened assets take
        // January and February, the purchase takes February.
        $engine->run(CarbonImmutable::parse('2026-02-28'));

        try {
            $engine->run(CarbonImmutable::parse('2026-02-28'));
            $this->fail('A second run through February posted something.');
        } catch (RunRejected) {
        }

        // March closes unposted; April's run carries March as a catch-up.
        $this->periodContaining('2026-03-15')
            ->forceFill(['status' => PeriodStatus::Closed])->save();

        $aprilRun = $engine->run(CarbonImmutable::parse('2026-04-30'));

        $march = $this->periodContaining('2026-03-15');
        $april = $this->periodContaining('2026-04-15');

        $this->assertSame(
            3,
            DepreciationCharge::query()
                ->where('accounting_period_id', $march->getKey())
                ->where('posted_period_id', $april->getKey())
                ->count(),
        );

        // The reversal drops charges and money together; the re-run
        // reclaims the freed periods identically.
        $engine->reverse($aprilRun, CarbonImmutable::parse('2026-04-30'));
        $engine->run(CarbonImmutable::parse('2026-04-30'));

        $tie = app(FixedAssetsTie::class)->build();
        $this->assertTrue($tie['balanced'], 'The tie broke after runs and reversal.');

        // The forklift goes for 5,000 plus VAT in May; the line goes to
        // scrap in June.
        $sale = FixedAssetDisposal::create([
            'reference' => app(AssetDisposalPoster::class)->nextReference(AssetDisposalKind::Sale),
            'kind' => AssetDisposalKind::Sale,
            'fixed_asset_id' => $bought->getKey(),
            'disposal_date' => '2026-05-15',
            'proceeds' => '5000',
            'tax_id' => $tax->getKey(),
            'proceeds_account_id' => $this->accountByCode('1120')->getKey(),
        ]);

        app(AssetDisposalPoster::class)->approve($sale);

        $scrap = FixedAssetDisposal::create([
            'reference' => app(AssetDisposalPoster::class)->nextReference(AssetDisposalKind::Scrap),
            'kind' => AssetDisposalKind::Scrap,
            'fixed_asset_id' => $opened->getKey(),
            'disposal_date' => '2026-06-01',
        ]);

        app(AssetDisposalPoster::class)->approve($scrap);

        // The invariant, both roles, to the fourth decimal.
        $tie = app(FixedAssetsTie::class)->build();

        $this->assertTrue($tie['balanced'], 'The closing tie broke.');

        foreach ($tie['rows'] as $row) {
            $this->assertSame(
                0,
                bccomp('0', $row['difference'], 4),
                "Account {$row['account']?->code} ({$row['role']}) is off by {$row['difference']}.",
            );
        }

        // And the income statement's depreciation is exactly the sum of the
        // charge rows — the subledger and the P&L are one number.
        $chargesTotal = (string) (DepreciationCharge::query()->sum('amount') ?: '0');

        $this->assertSame(
            0,
            bccomp(
                $chargesTotal,
                $this->balanceOf($this->accountByKey('depreciation_expense')),
                4,
            ),
        );

        $this->assertGreaterThan(0, DepreciationCharge::query()->count());

        // The register still names what the GL holds: only the bridged
        // asset remains active.
        $activeCost = (string) (DB::table('fixed_assets')
            ->where('company_id', $this->company->getKey())
            ->where('status', 'active')
            ->sum('cost') ?: '0');

        $this->assertSame(0, bccomp('20000', $activeCost, 4));
        $this->assertSame(0, bccomp('20000', $this->balanceOf($this->accountByCode('1210')), 4));
    }
}
