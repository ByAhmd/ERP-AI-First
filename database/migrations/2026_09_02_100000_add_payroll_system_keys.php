<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The system keys the payroll slice stands on.
 *
 * The template now keys 2140، 2150 و5200 and ships five new leaves —
 * سلف الموظفين 1180، خصومات الموظفين 4320 and the three payroll expense
 * accounts 5250/5260/5270 — for new companies, but `createNode()` never
 * touches an existing account. The same backfill the inventory and
 * fixed-asset slices shipped, or the first payroll run on a pre-existing
 * tenant throws SystemAccountMissing.
 *
 * Keys existing accounts by the template's own codes (skipping any company
 * where the key was renamed onto another account), then creates the new
 * leaves through the model so path/depth/is_postable stay
 * observer-maintained.
 */
return new class extends Migration
{
    private const KEYS_BY_CODE = [
        '2140' => 'salaries_payable',
        '2150' => 'gosi_payable',
        '5200' => 'salaries_expense',
        '1180' => 'employee_advances',
        '4320' => 'employee_deductions_income',
        '5250' => 'direct_salaries_expense',
        '5260' => 'gosi_expense',
        '5270' => 'bonuses_expense',
    ];

    public function up(): void
    {
        foreach (self::KEYS_BY_CODE as $code => $key) {
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
                $this->createIfMissing($company, 'employee_advances', '1180',
                    'سلف الموظفين', 'Employee Advances', 'asset', '1100');
                $this->createIfMissing($company, 'employee_deductions_income', '4320',
                    'خصومات الموظفين', 'Employee Deductions Income', 'revenue', '4000');
                $this->createIfMissing($company, 'direct_salaries_expense', '5250',
                    'رواتب التكلفة المباشرة', 'Direct Labor Salaries', 'expense', '5000');
                $this->createIfMissing($company, 'gosi_expense', '5260',
                    'مصروف التأمينات الاجتماعية', 'GOSI Expense', 'expense', '5000');
                $this->createIfMissing($company, 'bonuses_expense', '5270',
                    'مكافآت الموظفين', 'Employee Bonuses', 'expense', '5000');
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

        // A renumbered chart may already use the code; the suffix walk
        // finds a free one rather than failing the migration.
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
            WHERE system_key IN ('salaries_payable', 'gosi_payable', 'salaries_expense',
                                 'employee_advances', 'employee_deductions_income',
                                 'direct_salaries_expense', 'gosi_expense', 'bonuses_expense')
        SQL);
    }
};
