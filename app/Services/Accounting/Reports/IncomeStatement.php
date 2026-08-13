<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Services\Accounting\AccountRegistry;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The income statement.
 *
 * What the company earned and spent across a period, and whether that left a
 * profit. Unlike the balance sheet it measures movement rather than position,
 * which is why every figure is bounded at both ends.
 *
 * Accrual only, as Qoyod is: an invoice counts on the day it is issued, not the
 * day it is paid. Cash-basis reporting is a different statement and presenting
 * one as the other would misstate every period.
 *
 * Expenses are split into three bands, each producing a subtotal a reader asks
 * for separately:
 *
 *  - Cost of sales, giving gross profit — the margin a trading company manages.
 *  - Operating expenses, giving the result before financing and statutory
 *    charges. Zakat is assessed on the company rather than incurred in earning
 *    revenue, so an operating result stated after it would misreport how the
 *    business actually performed. This is the subtotal Qoyod shows as
 *    "صافي الدخل قبل الفوائد والضريبة والزكاة", and a Saudi statement is
 *    expected to carry it.
 *  - Interest, tax and zakat, giving net profit.
 *
 * Each band is taken from an account role and everything beneath it, never from
 * a code. A company may renumber its chart, or break cost of sales into
 * materials, freight and direct labour, and every account still lands in the
 * right band without being configured.
 */
final class IncomeStatement
{
    private const SCALE = 4;

    public function __construct(
        private readonly LedgerBalances $balances,
        private readonly StatementTree $tree,
        private readonly AccountRegistry $registry,
    ) {}

    public function build(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?StatementOptions $options = null,
    ): FinancialStatement {
        $options ??= new StatementOptions;

        $periods = StatementPeriod::between($from, $to, $options->interval, $options->comparisons);

        $readings = array_map(
            fn (StatementPeriod $period): array => $this->balances->perAccount($period->range, $options->filters),
            $periods,
        );

        $costOfSales = $this->subtreeOf(SystemAccount::CostOfGoodsSold);
        $belowTheLine = $this->subtreeOf(SystemAccount::InterestTaxAndZakat);

        $expenses = $this->accountsOfType(AccountType::Expense);

        $revenueSection = $this->section(
            'revenue',
            $this->accountsOfType(AccountType::Revenue),
            $readings,
            $options,
        );

        $costOfSalesSection = $this->section(
            'cost_of_sales',
            $expenses->filter($this->within($costOfSales))->values(),
            $readings,
            $options,
        );

        $grossProfit = $this->subtract($revenueSection->totals, $costOfSalesSection->totals);

        // Everything that is neither cost of sales nor a financing or statutory
        // charge. Both are lifted out, so an account added anywhere else in the
        // chart still lands here without being told to.
        $operatingSection = $this->section(
            'operating_expenses',
            $expenses
                ->reject($this->within($costOfSales))
                ->reject($this->within($belowTheLine))
                ->values(),
            $readings,
            $options,
        );

        $operatingResult = $this->subtract($grossProfit, $operatingSection->totals);

        $belowTheLineSection = $this->section(
            'interest_tax_and_zakat',
            $expenses->filter($this->within($belowTheLine))->values(),
            $readings,
            $options,
        );

        $netProfit = $this->subtract($operatingResult, $belowTheLineSection->totals);

        return new FinancialStatement(
            periods: $periods,
            sections: [
                $revenueSection,
                $costOfSalesSection,
                StatementSection::summary('gross_profit', $grossProfit),
                $operatingSection,
                StatementSection::summary('operating_result', $operatingResult),
                $belowTheLineSection,
                StatementSection::summary('net_profit', $netProfit, emphasised: true),
            ],
            isFiltered: $options->filters->narrowsLines(),
        );
    }

    /**
     * Membership test for a set of account ids.
     *
     * @param  list<string>  $ids
     * @return callable(Account): bool
     */
    private function within(array $ids): callable
    {
        return static fn (Account $account): bool => in_array($account->getKey(), $ids, true);
    }

    /**
     * The account fulfilling a role, and every account beneath it.
     *
     * Resolved by role rather than by code, so a company that renumbers its
     * chart keeps a correctly classified statement, and one that breaks a
     * grouping into sub-accounts gets all of them in the right section without
     * configuring anything.
     *
     * @return list<string>
     */
    private function subtreeOf(SystemAccount $role): array
    {
        $root = $this->registry->find($role);

        if ($root === null || $root->path === null) {
            // The role has no account in this company — an older chart that
            // predates it, most likely. An empty section is the honest result;
            // guessing at codes would be worse.
            return [];
        }

        // The path is a dot-joined chain of account codes. Codes are the
        // company's to choose, so the wildcards are escaped rather than
        // assumed absent.
        $prefix = addcslashes($root->path, '%_\\');

        return Account::query()
            ->where(function ($query) use ($root, $prefix): void {
                $query->whereKey($root->getKey())
                    ->orWhere('path', 'like', $prefix.'.%');
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsOfType(AccountType $type): Collection
    {
        return Account::query()
            ->where('type', $type)
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  list<array<string, array{debit: string, credit: string}>>  $readings
     */
    private function section(
        string $key,
        Collection $accounts,
        array $readings,
        StatementOptions $options,
    ): StatementSection {
        $built = $this->tree->build($accounts, $readings, $options->depth, $options->includeEmpty);

        return new StatementSection(
            key: $key,
            lines: $built['lines'],
            totals: $built['totals'],
        );
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function subtract(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $index => $value) {
            $result[] = bcsub($value, $b[$index] ?? '0', self::SCALE);
        }

        return $result;
    }
}
