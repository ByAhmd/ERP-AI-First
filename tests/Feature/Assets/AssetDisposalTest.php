<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Enums\AssetDisposalKind;
use App\Enums\FixedAssetStatus;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Tax;
use App\Services\Assets\AssetDisposalPoster;
use App\Services\Assets\DepreciationEngine;
use App\Services\Assets\Exceptions\DisposalRejected;
use App\Services\Assets\Exceptions\RunRejected;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Disposal's ordering rules: depreciate to the date first, clear the posted
 * figures, tax the sale — and never come back.
 *
 * The disposal asset: cost 10,000, salvage 0, life 12 months, in service
 * 2026-01-01, bought through the bank. Daily rate 10,000/365; January is
 * 849.32 and the first fifteen days of February are 410.96.
 */
final class AssetDisposalTest extends AssetTestCase
{
    private function purchaseAsset(): FixedAsset
    {
        return $this->registerAsset([
            'acquisition_kind' => 'purchase',
            'cost' => '10000',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
        ]);
    }

    private function makeDisposal(
        FixedAsset $asset,
        AssetDisposalKind $kind,
        string $date = '2026-02-15',
        ?string $proceeds = null,
        ?string $taxId = null,
        ?string $proceedsAccountId = null,
    ): FixedAssetDisposal {
        return FixedAssetDisposal::create([
            'reference' => app(AssetDisposalPoster::class)->nextReference($kind),
            'kind' => $kind,
            'fixed_asset_id' => $asset->getKey(),
            'disposal_date' => $date,
            'proceeds' => $proceeds,
            'tax_id' => $taxId,
            'proceeds_account_id' => $proceedsAccountId,
        ]);
    }

    #[Test]
    public function a_sale_posts_the_final_charge_as_depreciation_and_carries_output_vat(): void
    {
        $asset = $this->purchaseAsset();

        $this->runThrough('2026-01-31', $asset);

        $disposal = $this->makeDisposal(
            $asset,
            AssetDisposalKind::Sale,
            proceeds: '9000',
            taxId: Tax::query()->where('is_default', true)->firstOrFail()->getKey(),
            proceedsAccountId: $this->accountByCode('1120')->getKey(),
        );

        $approved = app(AssetDisposalPoster::class)->approve($disposal);

        // The unregistered fifteen days landed in depreciation expense —
        // never inside the gain.
        $this->assertNotNull($approved->catchup_run_id);
        $this->assertSame(0, bccomp('1260.28', $this->balanceOf($this->accountByKey('depreciation_expense')), 4));

        $lines = $approved->journalEntry()->firstOrFail()
            ->lines()->with('account')->get()
            ->mapWithKeys(fn ($l): array => [$l->account->code => [
                'debit' => (string) $l->debit,
                'credit' => (string) $l->credit,
            ]]);

        // Gross into the bank, VAT to output tax, posted figures off the
        // books, the remainder a gain.
        $this->assertSame(0, bccomp('10350', $lines['1120']['debit'], 4));
        $this->assertSame(0, bccomp('1350', $lines['2120']['credit'], 4));
        $this->assertSame(0, bccomp('1260.28', $lines['1220']['debit'], 4));
        $this->assertSame(0, bccomp('10000', $lines['1210']['credit'], 4));
        $this->assertSame(0, bccomp('260.28', $lines['4310']['credit'], 4));

        $this->assertSame(0, bccomp('260.28', (string) $approved->gain_loss_amount, 4));
        $this->assertSame(0, bccomp('10000', (string) $approved->cost_removed, 4));
        $this->assertSame(0, bccomp('1260.28', (string) $approved->accumulated_removed, 4));

        $this->assertSame(FixedAssetStatus::Disposed, $asset->refresh()->status);
    }

    #[Test]
    public function a_scrap_clears_posted_accumulated_not_a_recomputation(): void
    {
        $asset = $this->purchaseAsset();

        // A reversal in the history must change nothing about what disposal
        // reads: the posted rows are the record.
        $run = $this->runThrough('2026-01-31', $asset);
        app(DepreciationEngine::class)->reverse($run, CarbonImmutable::parse('2026-01-31'));
        $this->runThrough('2026-01-31', $asset);

        $disposal = $this->makeDisposal($asset, AssetDisposalKind::Scrap, date: '2026-01-31');

        $approved = app(AssetDisposalPoster::class)->approve($disposal);

        // No unposted days remained, so no catch-up run was needed.
        $this->assertNull($approved->catchup_run_id);
        $this->assertSame(0, bccomp('849.32', (string) $approved->accumulated_removed, 4));
        $this->assertSame(0, bccomp('-9150.68', (string) $approved->gain_loss_amount, 4));

        $lines = $approved->journalEntry()->firstOrFail()
            ->lines()->with('account')->get()
            ->mapWithKeys(fn ($l): array => [$l->account->code => [
                'debit' => (string) $l->debit,
                'credit' => (string) $l->credit,
            ]]);

        $this->assertSame(0, bccomp('849.32', $lines['1220']['debit'], 4));
        $this->assertSame(0, bccomp('10000', $lines['1210']['credit'], 4));
        $this->assertSame(0, bccomp('9150.68', $lines['5955']['debit'], 4));

        // Both control accounts washed clean for this asset.
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByCode('1210')), 4));
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByCode('1220')), 4));
    }

    #[Test]
    public function a_disposed_asset_never_depreciates_again(): void
    {
        $asset = $this->purchaseAsset();

        $disposal = $this->makeDisposal($asset, AssetDisposalKind::Scrap);
        app(AssetDisposalPoster::class)->approve($disposal);

        $this->expectException(RunRejected::class);

        $this->runThrough('2026-03-31', $asset);
    }

    #[Test]
    public function disposal_guards_hold(): void
    {
        $asset = $this->purchaseAsset();

        // A sale without proceeds is not a sale.
        $bare = $this->makeDisposal($asset, AssetDisposalKind::Sale);

        try {
            app(AssetDisposalPoster::class)->approve($bare);
            $this->fail('A sale without proceeds was approved.');
        } catch (DisposalRejected) {
        }

        $scrap = $this->makeDisposal($asset, AssetDisposalKind::Scrap);
        app(AssetDisposalPoster::class)->approve($scrap);

        // Twice is once too many.
        try {
            app(AssetDisposalPoster::class)->approve($scrap->refresh());
            $this->fail('An approved disposal was approved again.');
        } catch (DisposalRejected) {
        }

        // And a second document against a disposed asset is refused.
        $second = $this->makeDisposal($asset, AssetDisposalKind::Scrap);

        try {
            app(AssetDisposalPoster::class)->approve($second);
            $this->fail('A disposed asset was disposed again.');
        } catch (DisposalRejected) {
        }

        // One approval survived; the cost left the books exactly once.
        $this->assertSame(1, FixedAssetDisposal::query()->where('status', 'approved')->count());
        $this->assertSame(0, bccomp('0', $this->balanceOf($this->accountByCode('1210')), 4));
    }

    #[Test]
    public function run_reversal_is_refused_for_catchup_runs_and_disposed_assets(): void
    {
        $asset = $this->purchaseAsset();

        $januaryRun = $this->runThrough('2026-01-31', $asset);

        $disposal = $this->makeDisposal(
            $asset,
            AssetDisposalKind::Sale,
            proceeds: '9000',
            proceedsAccountId: $this->accountByCode('1120')->getKey(),
        );

        $approved = app(AssetDisposalPoster::class)->approve($disposal);

        // The catch-up is part of the disposal's arithmetic.
        try {
            app(DepreciationEngine::class)->reverse(
                $approved->catchupRun()->firstOrFail(),
                CarbonImmutable::parse('2026-02-15'),
            );
            $this->fail('A disposal catch-up run was reversed.');
        } catch (RunRejected) {
        }

        // And the January run's charges are baked into the disposal
        // snapshots — deleting them now would falsify the record.
        try {
            app(DepreciationEngine::class)->reverse($januaryRun, CarbonImmutable::parse('2026-02-15'));
            $this->fail('A run touching a disposed asset was reversed.');
        } catch (RunRejected) {
        }

        // Nothing moved: both runs still stand, charges intact.
        $this->assertSame('approved', $januaryRun->refresh()->status->value);
        $this->assertSame(2, \App\Models\DepreciationCharge::query()->count());
    }
}
