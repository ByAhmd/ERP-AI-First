<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\Account;
use App\Models\Tax;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\JournalPoster;
use App\Services\Assets\Exceptions\AssetRuleViolation;
use App\Services\Assets\Reports\FixedAssetsTie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The register's birth doors, and the backfill old tenants stand on.
 */
final class AssetRegistrarTest extends AssetTestCase
{
    #[Test]
    public function a_posted_opening_lands_cost_accumulated_and_suspense(): void
    {
        $asset = $this->registerAsset([
            'register_only' => false,
            'opening_accumulated_depreciation' => '2000',
            'opening_depreciated_through' => '2025-12-31',
            'useful_life_months' => 36,
        ]);

        $this->assertNotNull($asset->acquisition_journal_entry_id);

        $lines = $asset->acquisitionJournalEntry()->firstOrFail()
            ->lines()->with('account')->get()
            ->mapWithKeys(fn ($l): array => [$l->account->code => [
                'debit' => (string) $l->debit,
                'credit' => (string) $l->credit,
            ]]);

        $this->assertSame(0, bccomp('10000', $lines['1210']['debit'], 4));
        $this->assertSame(0, bccomp('2000', $lines['1220']['credit'], 4));
        $this->assertSame(0, bccomp('8000', $lines['3900']['credit'], 4));

        $this->assertSame(0, bccomp('2000.0000', $asset->accumulatedDepreciation(), 4));
        $this->assertSame(0, bccomp('8000.0000', $asset->bookValue(), 4));
    }

    #[Test]
    public function a_register_only_opening_posts_nothing_and_bridges_existing_balances(): void
    {
        // The pre-existing life: 1210 already carries the asset from before
        // the module existed.
        app(JournalPoster::class)->post(
            CarbonImmutable::parse('2026-01-01'),
            [
                JournalLineData::debit($this->accountByCode('1210')->getKey(), '10000'),
                JournalLineData::credit($this->accountByCode('3900')->getKey(), '10000'),
            ],
            description: 'رصيد قائم قبل السجل',
        );

        $asset = $this->registerAsset();

        $this->assertNull($asset->acquisition_journal_entry_id);

        // The bridge holds: GL and register agree without a second posting.
        $tie = app(FixedAssetsTie::class)->build();

        $this->assertTrue($tie['balanced']);
    }

    #[Test]
    public function a_manual_purchase_debits_cost_and_vat_and_credits_a_payment_account(): void
    {
        $tax = Tax::query()->where('is_default', true)->firstOrFail();

        $asset = $this->registerAsset([
            'acquisition_kind' => 'purchase',
            'cost' => '8000',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            'payment_account_id' => $this->accountByCode('1120')->getKey(),
            'tax_id' => $tax->getKey(),
        ]);

        $lines = $asset->acquisitionJournalEntry()->firstOrFail()
            ->lines()->with('account')->get()
            ->mapWithKeys(fn ($l): array => [$l->account->code => [
                'debit' => (string) $l->debit,
                'credit' => (string) $l->credit,
            ]]);

        $this->assertSame(0, bccomp('8000', $lines['1210']['debit'], 4));
        $this->assertSame(0, bccomp('1200', $lines['1150']['debit'], 4));
        $this->assertSame(0, bccomp('9200', $lines['1120']['credit'], 4));
    }

    #[Test]
    public function a_manual_purchase_refuses_a_non_payment_account(): void
    {
        $this->expectException(AssetRuleViolation::class);

        $this->registerAsset([
            'acquisition_kind' => 'purchase',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            // Receivables control — postable, but no money lives there.
            'payment_account_id' => $this->accountByCode('1130')->getKey(),
        ]);
    }

    #[Test]
    public function figure_guards_refuse_bad_registrations(): void
    {
        try {
            $this->registerAsset(['salvage_value' => '10000']);
            $this->fail('Salvage equal to cost was accepted.');
        } catch (AssetRuleViolation) {
        }

        try {
            $this->registerAsset([
                'opening_accumulated_depreciation' => '9500',
                'opening_depreciated_through' => '2025-12-31',
            ]);
            $this->fail('Opening accumulated above the base was accepted.');
        } catch (AssetRuleViolation) {
        }

        try {
            $this->registerAsset(['opening_accumulated_depreciation' => '500']);
            $this->fail('Opening accumulated without a last-depreciated date was accepted.');
        } catch (AssetRuleViolation) {
        }

        try {
            $this->registerAsset(['useful_life_months' => null]);
            $this->fail('A depreciable asset without a life was accepted.');
        } catch (AssetRuleViolation) {
        }

        $this->assertSame(0, \App\Models\FixedAsset::query()->count());
    }

    #[Test]
    public function the_system_key_backfill_covers_existing_tenants(): void
    {
        // Simulate the pre-module tenant: keys never assigned, disposal
        // accounts never created. Raw statements, to sidestep the observers
        // that would otherwise defend the rows.
        DB::statement("UPDATE chart_of_accounts SET system_key = NULL, is_system = 0
            WHERE system_key IN ('fixed_assets', 'accumulated_depreciation', 'depreciation_expense',
                                 'gain_on_asset_disposal', 'loss_on_asset_disposal')");
        DB::statement("DELETE FROM chart_of_accounts WHERE code IN ('4310', '5955')");

        $migration = require base_path('database/migrations/2026_09_01_100000_add_fixed_asset_system_keys.php');
        $migration->up();

        $this->assertSame('fixed_assets', $this->accountByCode('1210')->system_key);
        $this->assertSame('accumulated_depreciation', $this->accountByCode('1220')->system_key);
        $this->assertSame('depreciation_expense', $this->accountByCode('5500')->system_key);

        // The disposal-result accounts are recreated through the model, so
        // the observer-maintained tree fields are real.
        $gain = $this->accountByCode('4310');
        $this->assertSame('gain_on_asset_disposal', $gain->system_key);
        $this->assertSame($this->accountByCode('4000')->getKey(), $gain->parent_id);
        $this->assertTrue($gain->is_postable);
        $this->assertNotNull($gain->path);
        $this->assertGreaterThan(0, $gain->depth);

        $loss = $this->accountByCode('5955');
        $this->assertSame('loss_on_asset_disposal', $loss->system_key);
        $this->assertSame($this->accountByCode('5000')->getKey(), $loss->parent_id);

        // A key renamed onto another account is respected: re-running must
        // not key the template code a second time.
        DB::statement("UPDATE chart_of_accounts SET system_key = NULL, is_system = 0 WHERE code = '1210'");

        $custom = Account::query()->where('code', '1230')->firstOrFail();
        DB::statement("UPDATE chart_of_accounts SET system_key = 'fixed_assets', is_system = 1 WHERE id = '{$custom->getKey()}'");

        $migration->up();

        $this->assertNull($this->accountByCode('1210')->refresh()->system_key);
        $this->assertSame(1, Account::query()->where('system_key', 'fixed_assets')->count());
    }
}
