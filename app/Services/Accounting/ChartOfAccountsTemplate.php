<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * A starting chart of accounts for a Saudi SME.
 *
 * Deliberately conventional: 1000 assets, 2000 liabilities, 3000 equity, 4000
 * revenue, 5000 expenses. A Saudi accountant opening this platform for the first
 * time should recognise the structure without being taught it.
 *
 * Accounts the platform itself posts to carry a {@see SystemAccount} key, so
 * modules resolve them by role rather than by code and the company stays free to
 * renumber. The company can rename, renumber and extend everything here; it
 * cannot delete the keyed accounts, because posting logic depends on them
 * existing.
 *
 * Idempotent: re-running adds anything missing and leaves existing accounts,
 * including renamed ones, untouched.
 */
final class ChartOfAccountsTemplate
{
    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * Apply the template to a company.
     *
     * @return int Number of accounts created.
     */
    public function applyTo(Company $company): int
    {
        return $this->context->forCompany($company, function (): int {
            return DB::transaction(function (): int {
                $created = 0;

                foreach ($this->definition() as $node) {
                    $created += $this->createNode($node, null);
                }

                return $created;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function createNode(array $node, ?Account $parent): int
    {
        $existing = Account::query()->where('code', $node['code'])->first();

        $created = 0;

        if ($existing === null) {
            $existing = Account::create([
                'code' => $node['code'],
                'name' => $node['name'],
                'name_en' => $node['name_en'],
                'type' => $node['type'],
                'parent_id' => $parent?->getKey(),
                'is_system' => isset($node['key']),
                'system_key' => isset($node['key']) ? $node['key']->value : null,
            ]);

            $created = 1;
        }

        foreach ($node['children'] ?? [] as $child) {
            $created += $this->createNode($child, $existing);
        }

        return $created;
    }

    /**
     * The template.
     *
     * @return list<array<string, mixed>>
     */
    private function definition(): array
    {
        $asset = AccountType::Asset;
        $liability = AccountType::Liability;
        $equity = AccountType::Equity;
        $revenue = AccountType::Revenue;
        $expense = AccountType::Expense;

        return [
            $this->node('1000', 'الأصول', 'Assets', $asset, children: [
                $this->node('1100', 'الأصول المتداولة', 'Current Assets', $asset, children: [
                    $this->node('1110', 'النقد في الصندوق', 'Cash on Hand', $asset),
                    $this->node('1120', 'النقد لدى البنوك', 'Cash at Bank', $asset),
                    $this->node('1130', 'الذمم المدينة', 'Accounts Receivable', $asset, SystemAccount::AccountsReceivable),
                    $this->node('1140', 'المخزون', 'Inventory', $asset, SystemAccount::Inventory),
                    // Recoverable input VAT is an asset: a claim against ZATCA.
                    $this->node('1150', 'ضريبة القيمة المضافة على المشتريات', 'VAT Input (Recoverable)', $asset, SystemAccount::VatInputRecoverable),
                    $this->node('1160', 'مصروفات مدفوعة مقدماً', 'Prepaid Expenses', $asset),
                    $this->node('1170', 'دفعات مقدمة للموردين', 'Advances to Suppliers', $asset),
                ]),
                $this->node('1200', 'الأصول غير المتداولة', 'Non-Current Assets', $asset, children: [
                    $this->node('1210', 'الممتلكات والآلات والمعدات', 'Property, Plant and Equipment', $asset),
                    // Contra-asset: credit-normal despite being classified as an
                    // asset, which is why it is presented beneath the asset it
                    // offsets rather than among the liabilities.
                    $this->node('1220', 'مجمع الإهلاك', 'Accumulated Depreciation', $asset),
                    $this->node('1230', 'الأصول غير الملموسة', 'Intangible Assets', $asset),
                ]),
            ]),

            $this->node('2000', 'الالتزامات', 'Liabilities', $liability, children: [
                $this->node('2100', 'الالتزامات المتداولة', 'Current Liabilities', $liability, children: [
                    $this->node('2110', 'الذمم الدائنة', 'Accounts Payable', $liability, SystemAccount::AccountsPayable),
                    // Output VAT collected on sales and owed to ZATCA. The
                    // predecessor never posted here at all, crediting revenue
                    // with the tax-inclusive amount instead.
                    $this->node('2120', 'ضريبة القيمة المضافة على المبيعات', 'VAT Output (Payable)', $liability, SystemAccount::VatOutputPayable),
                    $this->node('2130', 'مصروفات مستحقة', 'Accrued Expenses', $liability),
                    $this->node('2140', 'رواتب مستحقة', 'Salaries Payable', $liability),
                    $this->node('2150', 'التأمينات الاجتماعية المستحقة', 'GOSI Payable', $liability),
                    $this->node('2160', 'ضريبة الاستقطاع المستحقة', 'Withholding Tax Payable', $liability, SystemAccount::WithholdingTaxPayable),
                    $this->node('2170', 'الزكاة المستحقة', 'Zakat Payable', $liability, SystemAccount::ZakatPayable),
                    $this->node('2180', 'دفعات مقدمة من العملاء', 'Customer Advances', $liability),
                ]),
                $this->node('2200', 'الالتزامات غير المتداولة', 'Non-Current Liabilities', $liability, children: [
                    $this->node('2210', 'قروض طويلة الأجل', 'Long-Term Loans', $liability),
                    $this->node('2220', 'مخصص مكافأة نهاية الخدمة', 'End-of-Service Benefits Provision', $liability),
                ]),
            ]),

            $this->node('3000', 'حقوق الملكية', 'Equity', $equity, children: [
                $this->node('3100', 'رأس المال', 'Capital', $equity),
                $this->node('3200', 'الأرباح المبقاة', 'Retained Earnings', $equity, SystemAccount::RetainedEarnings),
                $this->node('3300', 'نتيجة العام الحالي', 'Current Year Result', $equity, SystemAccount::CurrentYearResult),
                $this->node('3400', 'المسحوبات', 'Drawings', $equity),
                $this->node('3900', 'حساب تسوية الأرصدة الافتتاحية', 'Opening Balance Suspense', $equity, SystemAccount::OpeningBalanceSuspense),
            ]),

            $this->node('4000', 'الإيرادات', 'Revenue', $revenue, children: [
                $this->node('4100', 'إيرادات المبيعات', 'Sales Revenue', $revenue, SystemAccount::SalesRevenue),
                $this->node('4200', 'إيرادات الخدمات', 'Service Revenue', $revenue),
                $this->node('4300', 'إيرادات أخرى', 'Other Income', $revenue),
                $this->node('4400', 'مردودات وخصومات المبيعات', 'Sales Returns and Discounts', $revenue),
                $this->node('4500', 'أرباح فروق العملة', 'Exchange Gain', $revenue, SystemAccount::ExchangeGain),
            ]),

            $this->node('5000', 'المصروفات', 'Expenses', $expense, children: [
                $this->node('5100', 'تكلفة البضاعة المباعة', 'Cost of Goods Sold', $expense, SystemAccount::CostOfGoodsSold),
                $this->node('5150', 'تسويات المخزون', 'Inventory Adjustments', $expense, SystemAccount::InventoryAdjustment),
                $this->node('5200', 'الرواتب والأجور', 'Salaries and Wages', $expense),
                $this->node('5300', 'الإيجارات', 'Rent', $expense),
                $this->node('5400', 'المرافق', 'Utilities', $expense),
                $this->node('5500', 'مصروف الإهلاك', 'Depreciation Expense', $expense),
                $this->node('5600', 'التسويق والدعاية', 'Marketing and Advertising', $expense),
                $this->node('5700', 'أتعاب مهنية', 'Professional Fees', $expense),
                $this->node('5800', 'مصاريف بنكية', 'Bank Charges', $expense),
                $this->node('5850', 'خسائر فروق العملة', 'Exchange Loss', $expense, SystemAccount::ExchangeLoss),
                $this->node('5900', 'فروقات التقريب', 'Rounding Differences', $expense, SystemAccount::RoundingDifference),
                $this->node('5950', 'مصروفات أخرى', 'Other Expenses', $expense),
                // Reported below the operating result, so the income statement
                // can state what the company earned before financing and
                // statutory charges. Grouped rather than scattered among the
                // operating expenses, because the subtotal is drawn above them.
                $this->node('5960', 'الفوائد والضرائب والزكاة', 'Interest, Tax and Zakat', $expense, SystemAccount::InterestTaxAndZakat, children: [
                    $this->node('5961', 'أعباء تمويلية', 'Interest and Financing Charges', $expense),
                    $this->node('5962', 'مصروف ضريبة الدخل', 'Income Tax Expense', $expense),
                    $this->node('5963', 'مصروف الزكاة', 'Zakat Expense', $expense),
                ]),
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function node(
        string $code,
        string $name,
        string $nameEn,
        AccountType $type,
        ?SystemAccount $key = null,
        array $children = [],
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'name_en' => $nameEn,
            'type' => $type,
            'key' => $key,
            'children' => $children,
        ];
    }
}
