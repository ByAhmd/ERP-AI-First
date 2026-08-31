<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Enums\JournalEntryStatus;
use App\Models\AccountingPeriod;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\FixedAssetType;
use App\Services\Assets\DepreciationEngine;
use App\Services\Assets\FixedAssetRegistrar;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Shared scaffolding for the fixed-asset slice's tests: a posting-ready
 * company, one classification pointed at the keyed accounts, and the small
 * vocabulary every invariant test speaks — register, run, balance.
 */
abstract class AssetTestCase extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    protected Company $company;

    protected Branch $branch;

    protected FixedAssetType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('شركة الأصول النموذجية');

        $this->makeChartOfAccounts($this->company);
        $this->makeFiscalYear($this->company, 2026);

        app(TaxTemplate::class)->applyTo($this->company);

        $this->branch = Branch::query()->where('is_default', true)->firstOrFail();

        $this->type = $this->makeType();
    }

    protected function makeType(string $name = 'مركبات', bool $depreciable = true): FixedAssetType
    {
        return FixedAssetType::create([
            'name' => $name,
            'asset_account_id' => $this->accountByKey('fixed_assets')->getKey(),
            'accumulated_depreciation_account_id' => $this->accountByKey('accumulated_depreciation')->getKey(),
            'depreciation_expense_account_id' => $this->accountByKey('depreciation_expense')->getKey(),
            'default_useful_life_months' => 36,
            'is_depreciable' => $depreciable,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function registerAsset(array $overrides = []): FixedAsset
    {
        return app(FixedAssetRegistrar::class)->register(array_merge([
            'fixed_asset_type_id' => $this->type->getKey(),
            'name' => 'سيارة نقل',
            'branch_id' => $this->branch->getKey(),
            'acquisition_kind' => 'opening',
            'acquisition_date' => '2026-01-01',
            'in_service_date' => '2026-01-01',
            'cost' => '10000',
            'salvage_value' => '1000',
            'useful_life_months' => 3,
            'register_only' => true,
        ], $overrides));
    }

    protected function runThrough(string $date, ?FixedAsset $only = null, ?FixedAssetType $type = null): DepreciationRun
    {
        return app(DepreciationEngine::class)->run(
            CarbonImmutable::parse($date),
            $type,
            $only,
        );
    }

    protected function accountByKey(string $systemKey): Account
    {
        return Account::query()->where('system_key', $systemKey)->firstOrFail();
    }

    protected function accountByCode(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Posted debit-minus-credit balance of an account, at scale 4.
     */
    protected function balanceOf(Account $account): string
    {
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->company->getKey())
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('l.account_id', $account->getKey())
            ->selectRaw('COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    protected function periodContaining(string $date): AccountingPeriod
    {
        return AccountingPeriod::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->firstOrFail();
    }
}
