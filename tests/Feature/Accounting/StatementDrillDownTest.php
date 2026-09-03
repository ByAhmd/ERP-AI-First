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
use App\Services\Accounting\Reports\CashFlowStatement;
use App\Services\Accounting\Reports\DrillDownResult;
use App\Services\Accounting\Reports\DrillKind;
use App\Services\Accounting\Reports\FinancialStatement;
use App\Services\Accounting\Reports\FinancialStatementDrillContext;
use App\Services\Accounting\Reports\IncomeStatement;
use App\Services\Accounting\Reports\ReportFilters;
use App\Services\Accounting\Reports\StatementDrillDown;
use App\Services\Accounting\Reports\StatementDrillTarget;
use App\Services\Accounting\Reports\StatementDrillTargets;
use App\Services\Accounting\Reports\StatementLine;
use App\Services\Accounting\Reports\StatementOptions;
use App\Services\Accounting\Reports\StatementPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Drill-down from financial statement rows into ledger detail and breakdowns.
 */
final class StatementDrillDownTest extends TestCase
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
        app(FiscalCalendar::class)->createYear($this->company, 2025);

        $this->poster = app(JournalPoster::class);
        $this->accounts = app(AccountRegistry::class);
    }

    #[Test]
    public function period_movements_match_the_income_statement_revenue_line(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $statement = $this->income('2026-01-01', '2026-06-30');
        $revenue = $this->accounts->get(SystemAccount::SalesRevenue);
        $line = $this->findLineByAccountId($statement->sections[0]->lines, $revenue->getKey());
        $this->assertNotNull($line);

        $result = $this->drill($line->drill, $statement, 0, $line->name);

        $this->assertSame(DrillKind::PeriodMovements, $result->kind);
        $this->assertSame('10000.0000', $result->periodCredit);
        $this->assertCount(1, $result->rows);
    }

    #[Test]
    public function gross_profit_breakdown_matches_the_summary_line(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '4000', CarbonImmutable::parse('2026-04-01'));

        $statement = $this->income('2026-01-01', '2026-06-30');
        $summary = collect($statement->sections)->first(fn ($s) => $s->key === 'gross_profit');
        $this->assertNotNull($summary);

        $result = $this->drill(
            StatementDrillTargets::grossProfit(),
            $statement,
            0,
            $summary->title(),
        );

        $this->assertSame(DrillKind::Composite, $result->kind);
        $this->assertSame($summary->totals[0], $result->total);
        $this->assertCount(2, $result->breakdownRows);
    }

    #[Test]
    public function operating_result_and_net_profit_breakdowns_match_the_statement(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));
        $this->postExpense('5300', '4000', CarbonImmutable::parse('2026-04-01'));

        $statement = $this->income('2026-01-01', '2026-06-30');

        foreach (['operating_result', 'net_profit'] as $key) {
            $summary = collect($statement->sections)->first(fn ($s) => $s->key === $key);
            $target = $key === 'operating_result'
                ? StatementDrillTargets::operatingResult()
                : StatementDrillTargets::netProfit();

            $result = $this->drill($target, $statement, 0, $summary->title());

            $this->assertSame($summary->totals[0], $result->total, $key);
        }
    }

    #[Test]
    public function section_total_breakdown_matches_the_revenue_section(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $statement = $this->income('2026-01-01', '2026-06-30');
        $section = $statement->sections[0];

        $result = $this->drill(
            StatementDrillTarget::sectionBreakdown('revenue'),
            $statement,
            0,
            $section->totalLabel(),
        );

        $this->assertSame(DrillKind::SectionBreakdown, $result->kind);
        $this->assertSame($section->totals[0], $result->total);
    }

    #[Test]
    public function cash_flow_interest_paid_breakdown_matches_the_operating_line(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $statement = app(CashFlowStatement::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-06-30'),
        );

        $operating = $statement->sections[0];
        $line = collect($operating->lines)->first(
            fn (StatementLine $line): bool => $line->name === __('accounting.statements.lines.operating_result'),
        );

        $this->assertNotNull($line);
        $result = $this->drill(
            StatementDrillTargets::operatingResult(),
            $statement,
            0,
            $line->name,
            from: '2026-01-01',
            to: '2026-06-30',
        );

        $this->assertSame($line->amounts[0], $result->total);
    }

    #[Test]
    public function subtree_drill_includes_child_account_movements(): void
    {
        $this->postSale('5000', '750', CarbonImmutable::parse('2026-02-01'));

        $assetsRoot = Account::query()->where('code', '1000')->firstOrFail();
        $period = StatementPeriod::asOf(
            CarbonImmutable::parse('2026-06-30'),
            ComparisonInterval::None,
            0,
        )[0];

        $result = $this->drill(
            StatementDrillTarget::subtree(DrillKind::CumulativeBalance, $assetsRoot->getKey()),
            new FinancialStatement(
                periods: [$period],
                sections: [],
            ),
            0,
            $assetsRoot->name,
            asOf: '2026-06-30',
        );

        $this->assertGreaterThanOrEqual(1, $result->rows->count());
    }

    /**
     * @param  list<StatementLine>  $lines
     */
    private function findLineByAccountId(array $lines, string $accountId): ?StatementLine
    {
        foreach ($lines as $line) {
            if ($line->accountId === $accountId) {
                return $line;
            }

            $child = $this->findLineByAccountId($line->children, $accountId);

            if ($child !== null) {
                return $child;
            }
        }

        return null;
    }

    private function income(string $from, string $to): FinancialStatement
    {
        return app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse($from),
            to: CarbonImmutable::parse($to),
        );
    }

    private function drill(
        ?StatementDrillTarget $target,
        FinancialStatement $statement,
        int $columnIndex,
        string $title,
        ?string $from = null,
        ?string $to = null,
        ?string $asOf = null,
    ): DrillDownResult {
        $period = $statement->periods[$columnIndex];

        return app(StatementDrillDown::class)->execute(
            target: $target,
            context: new FinancialStatementDrillContext(
                statement: $statement,
                columnIndex: $columnIndex,
                period: $period,
                filters: ReportFilters::none(),
                options: new StatementOptions,
                from: $from !== null ? CarbonImmutable::parse($from) : null,
                to: $to !== null ? CarbonImmutable::parse($to) : null,
                asOf: $asOf !== null ? CarbonImmutable::parse($asOf) : null,
            ),
            lineTitle: $title,
        );
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

    private function code(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->getKey();
    }
}
