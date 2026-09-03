<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\ComparisonInterval;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\Company;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\CashFlowStatement;
use App\Services\Accounting\Reports\FinancialStatement;
use App\Services\Accounting\Reports\IncomeStatement;
use App\Services\Accounting\Reports\ReportFilters;
use App\Services\Accounting\Reports\StatementLine;
use App\Services\Accounting\Reports\StatementOfChangesInEquity;
use App\Services\Accounting\Reports\StatementOptions;
use App\Services\Accounting\Reports\StatementSection;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The balance sheet and the income statement.
 *
 * The assertion that matters most is that the balance sheet balances, and it is
 * not a formality. Nothing in the platform posts a year-end closing entry, so
 * the profit sitting in revenue and expense accounts never reaches equity on
 * its own. Read literally the chart is out by exactly the company's lifetime
 * profit; the report derives that figure instead. If the derivation is ever
 * wrong these tests fail, rather than a reader discovering it in front of an
 * auditor.
 */
final class FinancialStatementsTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private JournalPoster $poster;

    private AccountRegistry $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        // A prior year, so "brought forward" has something to carry.
        app(FiscalCalendar::class)->createYear($this->company, 2025);

        $this->poster = app(JournalPoster::class);
        $this->accounts = app(AccountRegistry::class);
    }

    // -----------------------------------------------------------------------
    // The balance sheet
    // -----------------------------------------------------------------------

    #[Test]
    public function the_balance_sheet_balances_once_profit_is_carried_into_equity(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $statement = $this->balanceSheet('2026-06-30');

        $this->assertTrue($statement->isBalanced());

        // Cash 11,500 against VAT owed 1,500 and 10,000 of profit.
        $this->assertSame('11500.0000', $this->total($statement, 'assets'));
        $this->assertSame('1500.0000', $this->total($statement, 'liabilities'));
        $this->assertSame('10000.0000', $this->total($statement, 'equity'));
    }

    #[Test]
    public function equity_carries_the_result_no_account_holds(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '4000', CarbonImmutable::parse('2026-04-01'));

        [$brought, $current] = $this->derivedEquityLines($this->balanceSheet('2026-06-30'));

        $this->assertSame('0.0000', $brought->amounts[0]);
        $this->assertSame('6000.0000', $current->amounts[0]);

        // No account holds it: retained earnings is still empty, because
        // closing a year moves no balances.
        $retained = $this->accounts->get(SystemAccount::RetainedEarnings);
        $this->assertFalse($retained->journalLines()->exists());
    }

    #[Test]
    public function the_result_splits_at_the_start_of_the_current_fiscal_year(): void
    {
        $this->postSale('3000', '0', CarbonImmutable::parse('2025-08-20'));
        $this->postSale('7000', '0', CarbonImmutable::parse('2026-02-10'));

        [$brought, $current] = $this->derivedEquityLines($this->balanceSheet('2026-06-30'));

        $this->assertSame('3000.0000', $brought->amounts[0]);
        $this->assertSame('7000.0000', $current->amounts[0]);
    }

    #[Test]
    public function the_balance_sheet_ignores_everything_posted_after_its_date(): void
    {
        $this->postSale('1000', '0', CarbonImmutable::parse('2026-03-01'));
        $this->postSale('9999', '0', CarbonImmutable::parse('2026-09-01'));

        $statement = $this->balanceSheet('2026-06-30');

        $this->assertSame('1000.0000', $this->total($statement, 'assets'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function a_draft_entry_reaches_no_statement(): void
    {
        $this->poster->draft(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->code('1110'), '5000'),
                JournalLineData::credit($this->code('4100'), '5000'),
            ],
            description: 'Unposted',
        );

        $statement = $this->balanceSheet('2026-06-30');

        $this->assertSame('0.0000', $this->total($statement, 'assets'));
        $this->assertSame('0.0000', $this->total($this->incomeStatement('2026-01-01', '2026-12-31'), 'revenue'));
    }

    #[Test]
    public function a_contra_account_reads_negative_within_its_own_section(): void
    {
        // Accumulated depreciation is credit-balanced but classified as an
        // asset, so it belongs beneath the asset it offsets rather than among
        // the liabilities — shown negative, exactly as Qoyod shows an overdraft.
        $this->poster->post(
            date: CarbonImmutable::parse('2026-05-01'),
            lines: [
                JournalLineData::debit($this->code('5500'), '2000'),
                JournalLineData::credit($this->code('1220'), '2000'),
            ],
            description: 'Depreciation',
        );

        $statement = $this->balanceSheet('2026-06-30');

        $this->assertSame('-2000.0000', $this->total($statement, 'assets'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function a_statement_narrowed_to_a_branch_makes_no_claim_about_balancing(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $statement = app(BalanceSheet::class)->build(
            asOf: CarbonImmutable::parse('2026-06-30'),
            options: new StatementOptions(filters: new ReportFilters(branchId: 'whichever')),
        );

        // A branch filter selects individual lines, and an entry spanning two
        // branches gives only part of itself to either. Reporting such a
        // statement as broken would be wrong; reporting it as balanced would be
        // a claim this report cannot make.
        $this->assertNull($statement->isBalanced());
        $this->assertTrue($statement->isFiltered);
    }

    // -----------------------------------------------------------------------
    // The income statement
    // -----------------------------------------------------------------------

    #[Test]
    public function gross_profit_is_revenue_less_cost_of_sales(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));
        $this->postCostOfSales('6000', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '1000', CarbonImmutable::parse('2026-04-01'));

        $statement = $this->incomeStatement('2026-01-01', '2026-12-31');

        $this->assertSame('10000.0000', $this->total($statement, 'revenue'));
        $this->assertSame('6000.0000', $this->total($statement, 'cost_of_sales'));
        $this->assertSame('4000.0000', $this->total($statement, 'gross_profit'));
        $this->assertSame('1000.0000', $this->total($statement, 'operating_expenses'));
        $this->assertSame('3000.0000', $this->total($statement, 'net_profit'));
    }

    #[Test]
    public function zakat_and_interest_fall_below_the_operating_result(): void
    {
        // The subtotal a Saudi statement is expected to carry. Zakat is
        // assessed on the company rather than incurred in earning revenue, so
        // an operating result stated after it would misreport the business.
        $this->postSale('10000', '0', CarbonImmutable::parse('2026-03-15'));
        $this->postCostOfSales('4000', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '1000', CarbonImmutable::parse('2026-04-01'));
        $this->postExpense('5961', '300', CarbonImmutable::parse('2026-05-01'));
        $this->postExpense('5963', '700', CarbonImmutable::parse('2026-06-01'));

        $statement = $this->incomeStatement('2026-01-01', '2026-12-31');

        $this->assertSame('6000.0000', $this->total($statement, 'gross_profit'));
        $this->assertSame('1000.0000', $this->total($statement, 'operating_expenses'));

        // Interest and zakat are excluded here and charged below.
        $this->assertSame('5000.0000', $this->total($statement, 'operating_result'));
        $this->assertSame('1000.0000', $this->total($statement, 'interest_tax_and_zakat'));
        $this->assertSame('4000.0000', $this->total($statement, 'net_profit'));
    }

    #[Test]
    public function the_statement_reads_top_to_bottom_in_the_order_qoyod_uses(): void
    {
        $keys = array_map(
            static fn (StatementSection $section): string => $section->key,
            $this->incomeStatement('2026-01-01', '2026-12-31')->sections,
        );

        $this->assertSame([
            'revenue',
            'cost_of_sales',
            'gross_profit',
            'operating_expenses',
            'operating_result',
            'interest_tax_and_zakat',
            'net_profit',
        ], $keys);
    }

    #[Test]
    public function net_profit_still_ties_when_nothing_sits_below_the_line(): void
    {
        // A company with no financing or statutory charges must not be made to
        // look different: the two lower subtotals simply agree.
        $this->postSale('5000', '0', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '2000', CarbonImmutable::parse('2026-04-01'));

        $statement = $this->incomeStatement('2026-01-01', '2026-12-31');

        $this->assertSame('0.0000', $this->total($statement, 'interest_tax_and_zakat'));
        $this->assertSame(
            $this->total($statement, 'operating_result'),
            $this->total($statement, 'net_profit'),
        );
    }

    #[Test]
    public function below_the_line_charges_still_reach_the_balance_sheet_result(): void
    {
        // Splitting the statement into bands must not change what the company
        // actually earned.
        $this->postSale('10000', '0', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5963', '700', CarbonImmutable::parse('2026-06-01'));

        $netProfit = $this->total($this->incomeStatement('2026-01-01', '2026-12-31'), 'net_profit');

        [, $current] = $this->derivedEquityLines($this->balanceSheet('2026-12-31'));

        $this->assertSame('9300.0000', $netProfit);
        $this->assertSame($netProfit, $current->amounts[0]);
    }

    #[Test]
    public function cost_of_sales_is_taken_by_role_and_keeps_its_own_sub_accounts(): void
    {
        // A company that splits cost of sales into materials and freight must
        // get both in the cost of sales section, not in operating expenses.
        $parent = $this->accounts->get(SystemAccount::CostOfGoodsSold);

        $freight = Account::create([
            'code' => '5110',
            'name' => 'الشحن الوارد',
            'name_en' => 'Inbound Freight',
            'type' => $parent->type,
            'parent_id' => $parent->getKey(),
        ]);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-20'),
            lines: [
                JournalLineData::debit($freight->getKey(), '750'),
                JournalLineData::credit($this->code('1110'), '750'),
            ],
            description: 'Freight',
        );

        $statement = $this->incomeStatement('2026-01-01', '2026-12-31');

        $this->assertSame('750.0000', $this->total($statement, 'cost_of_sales'));
        $this->assertSame('0.0000', $this->total($statement, 'operating_expenses'));
    }

    #[Test]
    public function the_income_statement_covers_only_its_own_period(): void
    {
        $this->postSale('3000', '0', CarbonImmutable::parse('2025-08-20'));
        $this->postSale('7000', '0', CarbonImmutable::parse('2026-02-10'));

        $this->assertSame(
            '7000.0000',
            $this->total($this->incomeStatement('2026-01-01', '2026-12-31'), 'revenue'),
        );
    }

    #[Test]
    public function net_profit_ties_to_the_result_the_balance_sheet_carries(): void
    {
        // The two statements are computed by different code down different
        // paths. If they ever disagree, one of them is lying about the ledger.
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));
        $this->postCostOfSales('6000', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '1000', CarbonImmutable::parse('2026-04-01'));

        $netProfit = $this->total($this->incomeStatement('2026-01-01', '2026-12-31'), 'net_profit');

        [, $current] = $this->derivedEquityLines($this->balanceSheet('2026-12-31'));

        $this->assertSame($netProfit, $current->amounts[0]);
    }

    // -----------------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------------

    #[Test]
    public function comparison_adds_a_column_per_period_most_recent_first(): void
    {
        $this->postSale('1000', '0', CarbonImmutable::parse('2026-01-15'));
        $this->postSale('2000', '0', CarbonImmutable::parse('2026-02-15'));
        $this->postSale('4000', '0', CarbonImmutable::parse('2026-03-15'));

        $statement = app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse('2026-03-01'),
            to: CarbonImmutable::parse('2026-03-31'),
            options: new StatementOptions(interval: ComparisonInterval::Month, comparisons: 2),
        );

        $this->assertSame(3, $statement->columnCount());
        $this->assertTrue($statement->hasComparisons());

        // March, then February, then January.
        $this->assertSame(['4000.0000', '2000.0000', '1000.0000'], $this->totals($statement, 'revenue'));
    }

    #[Test]
    public function comparison_columns_are_capped_rather_than_unbounded(): void
    {
        $statement = app(BalanceSheet::class)->build(
            asOf: CarbonImmutable::parse('2026-06-30'),
            options: new StatementOptions(interval: ComparisonInterval::Year, comparisons: 500),
        );

        // Thirteen comparisons plus the current column.
        $this->assertSame(14, $statement->columnCount());
    }

    #[Test]
    public function a_month_comparison_does_not_slide_off_the_end_of_a_short_month(): void
    {
        // Carbon's plain subMonths() maps 31 March onto 3 March by rolling
        // through February. A column headed February that ends in March would
        // double-count three days.
        $statement = app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse('2026-03-01'),
            to: CarbonImmutable::parse('2026-03-31'),
            options: new StatementOptions(interval: ComparisonInterval::Month, comparisons: 1),
        );

        $this->assertSame('2026-02-28', $statement->periods[1]->range->end->toDateString());
    }

    #[Test]
    public function depth_folds_detail_into_the_row_above_without_changing_the_total(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $shallow = app(BalanceSheet::class)->build(
            asOf: CarbonImmutable::parse('2026-06-30'),
            options: new StatementOptions(depth: 1),
        );

        $deep = app(BalanceSheet::class)->build(
            asOf: CarbonImmutable::parse('2026-06-30'),
            options: new StatementOptions(depth: 3),
        );

        $shallowLines = $this->section($shallow, 'assets')->lines;
        $deepLines = $this->section($deep, 'assets')->lines;

        $this->assertSame([], $shallowLines[0]->children);
        $this->assertNotSame([], $deepLines[0]->children);

        // Folding changes what is itemised, never the arithmetic.
        $this->assertSame(
            $this->total($shallow, 'assets'),
            $this->total($deep, 'assets'),
        );
    }

    #[Test]
    public function accounts_with_nothing_in_them_stay_off_the_statement(): void
    {
        $this->postSale('1000', '0', CarbonImmutable::parse('2026-03-15'));

        $lean = $this->balanceSheet('2026-06-30');
        $full = app(BalanceSheet::class)->build(
            asOf: CarbonImmutable::parse('2026-06-30'),
            options: new StatementOptions(includeEmpty: true),
        );

        $this->assertLessThan(
            $this->countLines($this->section($full, 'assets')->lines),
            $this->countLines($this->section($lean, 'assets')->lines),
        );
    }

    #[Test]
    public function another_companys_entries_never_reach_this_companys_statement(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $rival = $this->makeOtherCompany('Globex Industrial');
        $this->makeChartOfAccounts($rival);
        $this->makeFiscalYear($rival, 2026);

        app(CompanyContext::class)->forCompany($rival, function (): void {
            app(AccountRegistry::class)->flush();

            app(JournalPoster::class)->post(
                date: CarbonImmutable::parse('2026-03-15'),
                lines: [
                    JournalLineData::debit($this->code('1110'), '999999'),
                    JournalLineData::credit($this->code('4100'), '999999'),
                ],
                description: 'Rival sale',
            );
        });

        app(AccountRegistry::class)->flush();

        $statement = $this->balanceSheet('2026-06-30');

        $this->assertSame('11500.0000', $this->total($statement, 'assets'));
        $this->assertTrue($statement->isBalanced());
    }

    // -----------------------------------------------------------------------
    // The cash flow statement
    // -----------------------------------------------------------------------

    #[Test]
    public function the_cash_flow_statement_reconciles_to_the_cash_accounts(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '2000', CarbonImmutable::parse('2026-04-01'));

        $statement = $this->cashFlow('2026-01-01', '2026-12-31');

        $this->assertTrue($statement->isBalanced());
        $this->assertSame('9500.0000', $this->total($statement, 'cash_closing'));
    }

    #[Test]
    public function a_credit_sale_reduces_operating_cash_flow_below_profit(): void
    {
        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->code('1130'), '10000'),
                JournalLineData::credit($this->code('4100'), '10000'),
            ],
            description: 'Credit sale',
        );

        $statement = $this->cashFlow('2026-01-01', '2026-12-31');

        $operating = $this->section($statement, 'operating');

        $this->assertSame('10000.0000', $operating->lines[0]->amounts[0]);
        $this->assertSame('0.0000', $this->total($statement, 'operating'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function depreciation_is_added_back_in_operating_cash_flow(): void
    {
        $this->poster->post(
            date: CarbonImmutable::parse('2026-05-01'),
            lines: [
                JournalLineData::debit($this->code('5500'), '2000'),
                JournalLineData::credit($this->code('1220'), '2000'),
            ],
            description: 'Depreciation',
        );

        $statement = $this->cashFlow('2026-01-01', '2026-12-31');

        $operating = $this->section($statement, 'operating');

        $depreciation = $operating->lines[1];

        $this->assertSame('2000.0000', $depreciation->amounts[0]);
        // Non-cash depreciation hits the P&L and is added back here, so a
        // depreciation-only period nets to zero operating cash flow.
        $this->assertSame('0.0000', $this->total($statement, 'operating'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function depreciation_raises_operating_cash_flow_above_the_operating_result(): void
    {
        $this->postSale('10000', '0', CarbonImmutable::parse('2026-03-15'));
        $this->poster->post(
            date: CarbonImmutable::parse('2026-05-01'),
            lines: [
                JournalLineData::debit($this->code('5500'), '2000'),
                JournalLineData::credit($this->code('1220'), '2000'),
            ],
            description: 'Depreciation',
        );

        $statement = $this->cashFlow('2026-01-01', '2026-12-31');

        $this->assertSame('8000.0000', $this->section($statement, 'operating')->lines[0]->amounts[0]);
        $this->assertSame('10000.0000', $this->total($statement, 'operating'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function fixed_asset_purchases_appear_in_investing_cash_flow(): void
    {
        $this->poster->post(
            date: CarbonImmutable::parse('2026-06-01'),
            lines: [
                JournalLineData::debit($this->code('1210'), '8000'),
                JournalLineData::credit($this->code('1110'), '8000'),
            ],
            description: 'Asset purchase',
        );

        $statement = $this->cashFlow('2026-01-01', '2026-12-31');

        $this->assertSame('-8000.0000', $this->total($statement, 'investing'));
        $this->assertSame('-8000.0000', $this->total($statement, 'net_change'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function loan_proceeds_appear_in_financing_cash_flow(): void
    {
        $this->poster->post(
            date: CarbonImmutable::parse('2026-07-01'),
            lines: [
                JournalLineData::debit($this->code('1120'), '25000'),
                JournalLineData::credit($this->code('2210'), '25000'),
            ],
            description: 'Loan',
        );

        $statement = $this->cashFlow('2026-01-01', '2026-12-31');

        $this->assertSame('25000.0000', $this->total($statement, 'financing'));
        $this->assertSame('25000.0000', $this->total($statement, 'cash_closing'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function opening_plus_net_change_equals_closing_cash(): void
    {
        $this->postSale('5000', '0', CarbonImmutable::parse('2026-02-01'));
        $this->postExpense('5300', '1500', CarbonImmutable::parse('2026-03-01'));
        $this->poster->post(
            date: CarbonImmutable::parse('2026-04-01'),
            lines: [
                JournalLineData::debit($this->code('1210'), '3000'),
                JournalLineData::credit($this->code('1110'), '3000'),
            ],
            description: 'Asset',
        );

        $statement = $this->cashFlow('2026-01-01', '2026-12-31');

        $opening = $this->total($statement, 'cash_opening');
        $net = $this->total($statement, 'net_change');
        $closing = $this->total($statement, 'cash_closing');

        $this->assertSame(bcadd($opening, $net, 4), $closing);
        $this->assertTrue($statement->isBalanced());
    }

    // -----------------------------------------------------------------------
    // The statement of changes in equity
    // -----------------------------------------------------------------------

    #[Test]
    public function the_equity_statement_reconciles_opening_profit_and_closing(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '2000', CarbonImmutable::parse('2026-04-01'));

        $statement = $this->equityChanges('2026-01-01', '2026-12-31');
        $balance = $this->balanceSheet('2026-12-31');

        $this->assertTrue($statement->isBalanced());
        $this->assertSame('0.0000', $this->total($statement, 'equity_opening'));
        $this->assertSame('8000.0000', $this->total($statement, 'equity_movements'));
        $this->assertSame('8000.0000', $this->total($statement, 'equity_closing'));
        $this->assertSame(
            $this->total($balance, 'equity'),
            $this->total($statement, 'equity_closing'),
        );
    }

    #[Test]
    public function capital_contributions_appear_on_the_equity_statement(): void
    {
        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-01'),
            lines: [
                JournalLineData::debit($this->code('1110'), '50000'),
                JournalLineData::credit($this->code('3100'), '50000'),
            ],
            description: 'Capital injection',
        );

        $statement = $this->equityChanges('2026-01-01', '2026-12-31');

        $this->assertSame('50000.0000', $this->total($statement, 'equity_movements'));
        $this->assertSame('50000.0000', $this->total($statement, 'equity_closing'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function drawings_reduce_equity_on_the_statement(): void
    {
        $this->postSale('20000', '0', CarbonImmutable::parse('2026-03-15'));
        $this->poster->post(
            date: CarbonImmutable::parse('2026-04-01'),
            lines: [
                JournalLineData::debit($this->code('3400'), '3000'),
                JournalLineData::credit($this->code('1110'), '3000'),
            ],
            description: 'Owner drawings',
        );

        $statement = $this->equityChanges('2026-01-01', '2026-12-31');

        $this->assertSame('17000.0000', $this->total($statement, 'equity_movements'));
        $this->assertSame('17000.0000', $this->total($statement, 'equity_closing'));
        $this->assertTrue($statement->isBalanced());
    }

    #[Test]
    public function opening_plus_movements_equals_closing_equity(): void
    {
        $this->postSale('10000', '0', CarbonImmutable::parse('2026-02-01'));
        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-01'),
            lines: [
                JournalLineData::debit($this->code('1110'), '25000'),
                JournalLineData::credit($this->code('3100'), '25000'),
            ],
            description: 'Capital',
        );
        $this->poster->post(
            date: CarbonImmutable::parse('2026-04-01'),
            lines: [
                JournalLineData::debit($this->code('3400'), '1000'),
                JournalLineData::credit($this->code('1110'), '1000'),
            ],
            description: 'Drawings',
        );

        $statement = $this->equityChanges('2026-01-01', '2026-12-31');

        $opening = $this->total($statement, 'equity_opening');
        $movements = $this->total($statement, 'equity_movements');
        $closing = $this->total($statement, 'equity_closing');

        $this->assertSame(bcadd($opening, $movements, 4), $closing);
        $this->assertTrue($statement->isBalanced());
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function balanceSheet(string $asOf): FinancialStatement
    {
        return app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse($asOf));
    }

    private function incomeStatement(string $from, string $to): FinancialStatement
    {
        return app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse($from),
            to: CarbonImmutable::parse($to),
        );
    }

    private function cashFlow(string $from, string $to): FinancialStatement
    {
        return app(CashFlowStatement::class)->build(
            from: CarbonImmutable::parse($from),
            to: CarbonImmutable::parse($to),
        );
    }

    private function equityChanges(string $from, string $to): FinancialStatement
    {
        return app(StatementOfChangesInEquity::class)->build(
            from: CarbonImmutable::parse($from),
            to: CarbonImmutable::parse($to),
        );
    }

    private function section(FinancialStatement $statement, string $key): StatementSection
    {
        foreach ($statement->sections as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        $this->fail("The statement has no '{$key}' section.");
    }

    private function total(FinancialStatement $statement, string $key): string
    {
        return $this->section($statement, $key)->totals[0];
    }

    /**
     * @return list<string>
     */
    private function totals(FinancialStatement $statement, string $key): array
    {
        return $this->section($statement, $key)->totals;
    }

    /**
     * The two rows equity carries that no account holds.
     *
     * @return array{StatementLine, StatementLine}
     */
    private function derivedEquityLines(FinancialStatement $statement): array
    {
        $derived = array_values(array_filter(
            $this->section($statement, 'equity')->lines,
            static fn (StatementLine $line): bool => $line->isDerived,
        ));

        $this->assertCount(2, $derived);

        return [$derived[0], $derived[1]];
    }

    /**
     * @param  list<StatementLine>  $lines
     */
    private function countLines(array $lines): int
    {
        $count = 0;

        foreach ($lines as $line) {
            $count += 1 + $this->countLines($line->children);
        }

        return $count;
    }

    private function postSale(string $net, string $tax, CarbonImmutable $date): void
    {
        $lines = [
            JournalLineData::debit($this->code('1110'), bcadd($net, $tax, 4)),
            JournalLineData::credit($this->code('4100'), $net),
        ];

        if (bccomp($tax, '0', 4) !== 0) {
            $lines[] = JournalLineData::credit(
                $this->accounts->get(SystemAccount::VatOutputPayable)->getKey(),
                $tax,
            );
        }

        $this->poster->post(date: $date, lines: $lines, description: 'Sale');
    }

    private function postExpense(string $code, string $amount, CarbonImmutable $date): void
    {
        $this->poster->post(
            date: $date,
            lines: [
                JournalLineData::debit($this->code($code), $amount),
                JournalLineData::credit($this->code('1110'), $amount),
            ],
            description: 'Expense',
        );
    }

    private function postCostOfSales(string $amount, CarbonImmutable $date): void
    {
        $this->poster->post(
            date: $date,
            lines: [
                JournalLineData::debit(
                    $this->accounts->get(SystemAccount::CostOfGoodsSold)->getKey(),
                    $amount,
                ),
                JournalLineData::credit($this->code('1140'), $amount),
            ],
            description: 'Cost of sales',
        );
    }

    private function code(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->getKey();
    }
}
