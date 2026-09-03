<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The statement of changes in equity.
 *
 * Reconciles opening and closing equity across a period: what the owners had at
 * the start, the profit the business earned, any capital they put in or took
 * out, and what is left at the end. Profit is taken from the income statement
 * because nothing posts it into equity until a year is closed — the same choice
 * the balance sheet makes when it derives the current result rather than
 * reading it from an account.
 *
 * Qoyod presents this as columns per equity component; ours is a reconciliation
 * in rows because that is the shape every other financial statement already
 * uses. Each equity account with a ledger movement during the period gets its
 * own line; profit sits above them as the change the accounts do not yet carry.
 */
final class StatementOfChangesInEquity
{
    private const SCALE = 4;

    public function __construct(
        private readonly LedgerBalances $balances,
        private readonly IncomeStatement $incomeStatement,
        private readonly BalanceSheet $balanceSheet,
    ) {}

    public function build(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?StatementOptions $options = null,
    ): FinancialStatement {
        $options ??= new StatementOptions;

        $periods = StatementPeriod::between($from, $to, $options->interval, $options->comparisons);
        $columns = count($periods);

        $income = $this->incomeStatement->build($from, $to, $options);
        $netProfit = $this->sectionTotals($income, 'net_profit');

        $opening = $this->equityTotalsAtOpens($periods, $options);
        $closing = $this->equityTotalsAtEnds($periods, $options);

        $accountLines = $this->accountMovementLines($periods, $options);
        $accountMovements = $accountLines === []
            ? $this->zeros($columns)
            : $this->lineAmounts($accountLines);

        $lines = [
            StatementLine::derived(__('accounting.statements.lines.net_profit'), $netProfit),
            ...$accountLines,
        ];

        $movements = $this->sum([$netProfit, $accountMovements]);
        $computedClosing = $this->add($opening, $movements);
        $reconciliation = $this->subtract($computedClosing, $closing);

        return new FinancialStatement(
            periods: $periods,
            sections: [
                StatementSection::summary('equity_opening', $opening),
                new StatementSection(
                    key: 'equity_movements',
                    lines: $lines,
                    totals: $movements,
                ),
                StatementSection::summary('equity_closing', $closing, emphasised: true),
            ],
            isFiltered: $options->filters->narrowsLines(),
            imbalance: $options->filters->narrowsLines() ? null : $reconciliation,
            imbalanceMessage: 'accounting.statements.equity_out_of_balance',
        );
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function equityTotalsAtOpens(array $periods, StatementOptions $options): array
    {
        $amounts = [];

        foreach ($periods as $period) {
            $start = $period->range->start;

            if ($start === null) {
                $amounts[] = $this->zero();

                continue;
            }

            $amounts[] = $this->equityTotal(
                DateRange::endingBefore($start),
                $options,
            );
        }

        return $amounts;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function equityTotalsAtEnds(array $periods, StatementOptions $options): array
    {
        $amounts = [];

        foreach ($periods as $period) {
            $amounts[] = $this->equityTotal(
                DateRange::upTo($period->range->end),
                $options,
            );
        }

        return $amounts;
    }

    private function equityTotal(DateRange $range, StatementOptions $options): string
    {
        if ($range->isEmpty()) {
            return $this->zero();
        }

        $statement = $this->balanceSheet->build(
            asOf: $range->end,
            options: new StatementOptions(
                filters: $options->filters,
                interval: $options->interval,
                comparisons: 0,
                depth: $options->depth,
                includeEmpty: $options->includeEmpty,
            ),
        );

        foreach ($statement->sections as $section) {
            if ($section->key === 'equity') {
                return $section->totals[0] ?? $this->zero();
            }
        }

        return $this->zero();
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<StatementLine>
     */
    private function accountMovementLines(array $periods, StatementOptions $options): array
    {
        $lines = [];

        foreach ($this->equityAccounts() as $account) {
            $amounts = $this->equityAccountEffect($account, $periods, $options->filters);

            if (! $options->includeEmpty && ! $this->hasNonZero($amounts)) {
                continue;
            }

            $lines[] = StatementLine::derived($this->accountName($account), $amounts);
        }

        return $lines;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function equityAccountEffect(
        Account $account,
        array $periods,
        ReportFilters $filters,
    ): array {
        $amounts = [];

        foreach ($periods as $period) {
            $start = $period->range->start;

            if ($start === null) {
                $amounts[] = $this->zero();

                continue;
            }

            $change = bcsub(
                $this->signedBalance($account, DateRange::upTo($period->range->end), $filters),
                $this->signedBalance($account, DateRange::endingBefore($start), $filters),
                self::SCALE,
            );

            // Drawings and other debit-normal equity contra-accounts reduce total
            // equity when they grow; flip the sign so a line reads as a reduction.
            $amounts[] = $account->type->normalBalance() === NormalBalance::Credit
                ? $change
                : $this->negate([$change])[0];
        }

        return $amounts;
    }

    /**
     * @return Collection<int, Account>
     */
    private function equityAccounts(): Collection
    {
        return Account::query()
            ->where('type', AccountType::Equity)
            ->orderBy('code')
            ->get();
    }

    private function signedBalance(Account $account, DateRange $range, ReportFilters $filters): string
    {
        if ($range->isEmpty()) {
            return $this->zero();
        }

        $totals = $this->balances->perAccount($range, $filters);
        $id = $account->getKey();
        $debit = $totals[$id]['debit'] ?? '0';
        $credit = $totals[$id]['credit'] ?? '0';

        return $account->type->normalBalance() === NormalBalance::Debit
            ? bcsub($debit, $credit, self::SCALE)
            : bcsub($credit, $debit, self::SCALE);
    }

    /**
     * @return list<string>
     */
    private function sectionTotals(FinancialStatement $statement, string $key): array
    {
        foreach ($statement->sections as $section) {
            if ($section->key === $key) {
                return $section->totals;
            }
        }

        return $this->zeros($statement->columnCount());
    }

    /**
     * @param  list<StatementLine>  $lines
     * @return list<string>
     */
    private function lineAmounts(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $columns = count($lines[0]->amounts);
        $totals = $this->zeros($columns);

        foreach ($lines as $line) {
            $totals = $this->add($totals, $line->amounts);
        }

        return $totals;
    }

    /**
     * @param  list<string>  $amounts
     */
    private function hasNonZero(array $amounts): bool
    {
        foreach ($amounts as $amount) {
            if (bccomp($amount, '0', self::SCALE) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<list<string>>  $sets
     * @return list<string>
     */
    private function sum(array $sets): array
    {
        if ($sets === []) {
            return [];
        }

        $columns = count($sets[0]);
        $totals = $this->zeros($columns);

        foreach ($sets as $set) {
            if ($set === []) {
                continue;
            }

            $totals = $this->add($totals, $set);
        }

        return $totals;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function add(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $index => $value) {
            $result[] = bcadd($value, $b[$index] ?? '0', self::SCALE);
        }

        return $result;
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

    /**
     * @param  list<string>  $amounts
     * @return list<string>
     */
    private function negate(array $amounts): array
    {
        return array_map(
            fn (string $amount): string => bccomp($amount, '0', self::SCALE) === 0
                ? $amount
                : bcsub('0', $amount, self::SCALE),
            $amounts,
        );
    }

    /**
     * @return list<string>
     */
    private function zeros(int $columns): array
    {
        return array_fill(0, $columns, $this->zero());
    }

    private function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    private function accountName(Account $account): string
    {
        return app()->getLocale() === 'en' && filled($account->name_en)
            ? $account->name_en
            : $account->name;
    }
}
