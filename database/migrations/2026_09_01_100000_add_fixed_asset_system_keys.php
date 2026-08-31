<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The system keys the fixed-assets slice stands on.
 *
 * The template now keys 1210، 1220 و5500 and ships the two disposal-result
 * leaves 4310 و5955 for new companies, but `createNode()` deliberately never
 * touches an existing account — the same trap the inventory, customer-advance
 * and supplier-advance slices each left a backfill for. Without this, the
 * first depreciation run on a pre-existing tenant throws SystemAccountMissing.
 *
 * Two steps: key existing accounts by the template's own codes (skipping
 * anything a key was already renamed onto), then create the disposal-result
 * accounts for companies that lack them — through the model, because
 * path/depth/is_postable are observer-maintained and a raw insert would
 * corrupt the materialised path.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            '1210' => 'fixed_assets',
            '1220' => 'accumulated_depreciation',
            '5500' => 'depreciation_expense',
            '4310' => 'gain_on_asset_disposal',
            '5955' => 'loss_on_asset_disposal',
        ] as $code => $key) {
            DB::statement(<<<SQL
                UPDATE chart_of_accounts
                SET system_key = '{$key}', is_system = 1
                WHERE code = '{$code}'
                  AND system_key IS NULL
                  AND deleted_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM (SELECT company_id AS cid FROM chart_of_accounts
                                     WHERE system_key = '{$key}' AND deleted_at IS NULL) keyed
                      WHERE keyed.cid = chart_of_accounts.company_id
                  )
            SQL);
        }

        $context = app(CompanyContext::class);

        foreach (Company::query()->get() as $company) {
            $context->forCompany($company, function () use ($company): void {
                $this->createIfMissing($company, 'gain_on_asset_disposal', '4310',
                    'أرباح بيع أصول ثابتة', 'Gain on Disposal of Fixed Assets', 'revenue', '4000');
                $this->createIfMissing($company, 'loss_on_asset_disposal', '5955',
                    'خسائر بيع أصول ثابتة', 'Loss on Disposal of Fixed Assets', 'expense', '5000');
            });
        }
    }

    private function createIfMissing(
        Company $company,
        string $key,
        string $code,
        string $name,
        string $nameEn,
        string $type,
        string $parentCode,
    ): void {
        if (Account::query()->where('system_key', $key)->exists()) {
            return;
        }

        // A renumbered chart may already use the code for something else; the
        // suffix walk finds a free one rather than failing the migration.
        $free = $code;
        while (Account::query()->where('code', $free)->exists()) {
            $free .= '0';
        }

        Account::create([
            'company_id' => $company->getKey(),
            'parent_id' => Account::query()->where('code', $parentCode)->value('id'),
            'code' => $free,
            'name' => $name,
            'name_en' => $nameEn,
            'type' => $type,
            'is_system' => true,
            'system_key' => $key,
        ]);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE chart_of_accounts
            SET system_key = NULL, is_system = 0
            WHERE system_key IN ('fixed_assets', 'accumulated_depreciation', 'depreciation_expense',
                                 'gain_on_asset_disposal', 'loss_on_asset_disposal')
        SQL);
    }
};
