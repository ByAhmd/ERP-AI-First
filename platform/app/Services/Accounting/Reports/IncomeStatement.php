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
 * Cost of sales is separated from operating expenses so gross profit can be
 * shown, because gross margin is the number a trading company manages. The
 * split is taken from the account carrying the cost-of-sales role and
 * everything beneath it — role, not code, so a company that renumbers its chart
 * keeps a correct statement, and one that breaks cost of sales into materials,
 * freight and direct labour gets all three in the right section automatically.
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

        $costOfSalesIds = $this->costOfSalesSubtree();

        $revenue = $this->section(
            'revenue',
            $this->accountsOfType(AccountType::Revenue),
            $readings,
            $options,
        );

        $costOfSales = $this->section(
            'cost_of_sales',
            $this->accountsOfType(AccountType::Expense)
                ->filter(fn (Account $a): bool => in_array($a->getKey(), $costOfSalesIds, true))
                ->values(),
            $readings,
            $options,
        );

        $grossProfit = $this->subtract($revenue->totals, $costOfSales->totals);

        $expenses = $this->section(
            'operating_expenses',
            $this->accountsOfType(AccountType::Expense)
                ->reject(fn (Account $a): bool => in_array($a->getKey(), $costOfSalesIds, true))
                ->values(),
            $readings,
            $options,
        );

        $netProfit = $this->subtract($grossProfit, $expenses->totals);

        return new FinancialStatement(
            periods: $periods,
            sections: [
                $revenue,
                $costOfSales,
                StatementSection::summary('gross_profit', $grossProfit),
                $expenses,
                StatementSection::summary('net_profit', $netProfit, emphasised: true),
            ],
            isFiltered: $options->filters->narrowsLines(),
        );
    }

    /**
     * The cost-of-sales account and every account beneath it.
     *
     * @return list<string>
     */
    private function costOfSalesSubtree(): array
    {
        $root = $this->registry->find(SystemAccount::CostOfGoodsSold);

        if ($root === null || $root->path === null) {
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
