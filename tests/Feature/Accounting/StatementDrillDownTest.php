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
use App\Services\Accounting\Reports\DrillKind;
use App\Services\Accounting\Reports\IncomeStatement;
use App\Services\Accounting\Reports\ReportFilters;
use App\Services\Accounting\Reports\StatementDrillDown;
use App\Services\Accounting\Reports\StatementDrillTarget;
use App\Services\Accounting\Reports\StatementLine;
use App\Services\Accounting\Reports\StatementPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Drill-down from financial statement rows into ledger detail.
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

        $statement = app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-06-30'),
        );

        $revenue = $this->accounts->get(SystemAccount::SalesRevenue);
        $line = $this->findLineByAccountId($statement->sections[0]->lines, $revenue->getKey());
        $this->assertNotNull($line);

        $result = app(StatementDrillDown::class)->execute(
            target: $line->drill,
            period: $statement->periods[0],
            filters: ReportFilters::none(),
            lineTitle: $line->name,
        );

        $this->assertSame(DrillKind::PeriodMovements, $result->kind);
        $this->assertSame('10000.0000', $result->periodCredit);
        $this->assertSame('0.0000', $result->periodDebit);
        $this->assertCount(1, $result->rows);
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

        $result = app(StatementDrillDown::class)->execute(
            target: StatementDrillTarget::subtree(DrillKind::CumulativeBalance, $assetsRoot->getKey()),
            period: $period,
            filters: ReportFilters::none(),
            lineTitle: $assetsRoot->name,
        );

        $this->assertGreaterThanOrEqual(1, $result->rows->count());
        $this->assertSame(DrillKind::CumulativeBalance, $result->kind);
    }

    #[Test]
    public function balance_change_drill_carries_opening_and_closing(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $receivable = $this->accounts->get(SystemAccount::AccountsReceivable);
        $period = StatementPeriod::between(
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-06-30'),
            ComparisonInterval::None,
            0,
        )[0];

        $result = app(StatementDrillDown::class)->execute(
            target: StatementDrillTarget::account(DrillKind::BalanceChange, $receivable->getKey()),
            period: $period,
            filters: ReportFilters::none(),
            lineTitle: 'Receivable',
        );

        $this->assertSame('0.0000', $result->opening);
        $this->assertSame('0.0000', $result->closing);
    }

    #[Test]
    public function cash_flow_working_capital_lines_are_drillable(): void
    {
        $this->postSale('10000', '1500', CarbonImmutable::parse('2026-03-15'));

        $statement = app(CashFlowStatement::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-06-30'),
        );

        $vat = $this->accounts->get(SystemAccount::VatOutputPayable);

        $vatLine = collect($statement->sections[0]->lines)->first(
            static fn (StatementLine $line): bool => $line->isDrillable()
                && $line->accountId === $vat->getKey(),
        );

        $this->assertNotNull($vatLine);
        $this->assertSame(DrillKind::BalanceChange, $vatLine->drill?->kind);
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

    private function code(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->getKey();
    }
}
