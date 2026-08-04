<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Services\Accounting\FiscalYearCloser;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The balance sheet.
 *
 * Where the company stands on a date: what it owns, what it owes, and what is
 * left over for the owners. Every figure accumulates from the first entry ever
 * posted up to that date, which is what makes it a position rather than a
 * period.
 *
 * The profit belonging in equity is derived here rather than read from an
 * account, and that is the decision this report turns on. Nothing in the
 * platform posts a year-end closing entry — {@see FiscalYearCloser}
 * seals a year's status but moves no balances — so revenue and expense accounts
 * still carry their figures and retained earnings does not. Read literally, the
 * chart would show a company whose assets exceed its liabilities and equity by
 * exactly its lifetime profit.
 *
 * Deriving it is not a workaround for that. Assets less liabilities equals
 * accumulated profit is the accounting equation rearranged, so the statement
 * balances by construction and cannot be thrown out by a missing closing entry.
 * It also survives the opposite choice: were closing entries introduced later,
 * a closed year's income accounts would net to zero and the same arithmetic
 * would find the same total, now sitting in retained earnings instead.
 *
 * The result is split at the current fiscal year's start, because "earned
 * before this year" and "earned so far this year" are different questions and
 * an owner asks the second one.
 */
final class BalanceSheet
{
    private const SCALE = 4;

    public function __construct(
        private readonly LedgerBalances $balances,
        private readonly StatementTree $tree,
    ) {}

    public function build(
        DateTimeInterface $asOf,
        ?StatementOptions $options = null,
    ): FinancialStatement {
        $options ??= new StatementOptions;

        $periods = StatementPeriod::asOf($asOf, $options->interval, $options->comparisons);

        $readings = array_map(
            fn (StatementPeriod $period): array => $this->balances->perAccount($period->range, $options->filters),
            $periods,
        );

        $assets = $this->section('assets', AccountType::Asset, $readings, $options);
        $liabilities = $this->section('liabilities', AccountType::Liability, $readings, $options);
        $equity = $this->equity($periods, $readings, $options);

        $liabilitiesAndEquity = $this->add($liabilities->totals, $equity->totals);

        return new FinancialStatement(
            periods: $periods,
            sections: [
                $assets,
                $liabilities,
                $equity,
                StatementSection::summary('liabilities_and_equity', $liabilitiesAndEquity, emphasised: true),
            ],
            isFiltered: $options->filters->narrowsLines(),
            imbalance: $this->subtract($assets->totals, $liabilitiesAndEquity),
        );
    }

    /**
     * Equity, with the accumulated result folded in.
     *
     * @param  list<StatementPeriod>  $periods
     * @param  list<array<string, array{debit: string, credit: string}>>  $readings
     */
    private function equity(array $periods, array $readings, StatementOptions $options): StatementSection
    {
        $built = $this->tree->build(
            $this->accountsOfType(AccountType::Equity),
            $readings,
            $options->depth,
            $options->includeEmpty,
        );

        $brought = [];
        $current = [];

        foreach ($periods as $period) {
            [$brought[], $current[]] = $this->resultFor($period, $options);
        }

        $lines = $built['lines'];
        $lines[] = StatementLine::derived(__('accounting.statements.lines.brought_forward'), $brought);
        $lines[] = StatementLine::derived(__('accounting.statements.lines.current_result'), $current);

        return new StatementSection(
            key: 'equity',
            lines: $lines,
            totals: $this->add($built['totals'], $this->add($brought, $current)),
        );
    }

    /**
     * A column's accumulated result, split into earlier years and this one.
     *
     * @return array{string, string}
     */
    private function resultFor(StatementPeriod $period, StatementOptions $options): array
    {
        $asOf = $period->range->end;
        $year = $this->fiscalYearContaining($asOf);

        if ($year === null) {
            // No open year covers the date, so there is no "current period" to
            // separate out. In practice this only arises before the company
            // began trading, where the figure is zero anyway.
            return [$this->result(DateRange::upTo($asOf), $options), $this->zero()];
        }

        $start = $year->start_date;

        return [
            $this->result(DateRange::endingBefore($start), $options),
            $this->result(DateRange::between($start, $asOf), $options),
        ];
    }

    /**
     * Revenue less expenses over a window.
     *
     * Both classifications are summed in one reading and netted by side.
     * Revenue is credit-normal and expense debit-normal, so credits less debits
     * across the pair is the profit without either being handled separately.
     */
    private function result(DateRange $range, StatementOptions $options): string
    {
        $totals = $this->balances->forTypes(
            [AccountType::Revenue, AccountType::Expense],
            $range,
            $options->filters,
        );

        return bcsub($totals['credit'], $totals['debit'], self::SCALE);
    }

    private function fiscalYearContaining(CarbonImmutable $date): ?FiscalYear
    {
        return FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderBy('start_date')
            ->first();
    }

    /**
     * @param  list<array<string, array{debit: string, credit: string}>>  $readings
     */
    private function section(
        string $key,
        AccountType $type,
        array $readings,
        StatementOptions $options,
    ): StatementSection {
        $built = $this->tree->build(
            $this->accountsOfType($type),
            $readings,
            $options->depth,
            $options->includeEmpty,
        );

        return new StatementSection(
            key: $key,
            lines: $built['lines'],
            totals: $built['totals'],
        );
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

    private function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }
}
