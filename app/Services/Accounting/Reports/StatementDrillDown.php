<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\NormalBalance;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves a statement row's drill target into ledger movements.
 *
 * Every path reuses the same filters and date window the report column used, so
 * the detail a reader sees explains the figure they clicked rather than a
 * neighbouring period or an unfiltered total.
 */
final class StatementDrillDown
{
    private const SCALE = 4;

    private const EPOCH = '1970-01-01';

    public function __construct(
        private readonly GeneralLedger $ledger,
        private readonly LedgerBalances $balances,
    ) {}

    public function execute(
        StatementDrillTarget $target,
        StatementPeriod $period,
        ReportFilters $filters,
        string $lineTitle,
    ): DrillDownResult {
        $accounts = $this->resolveAccounts($target);

        return match ($target->kind) {
            DrillKind::PeriodMovements => $this->periodMovements(
                accounts: $accounts,
                range: $period->range,
                filters: $filters,
                title: $lineTitle,
                periodLabel: $period->label,
            ),
            DrillKind::CumulativeBalance => $this->cumulativeBalance(
                accounts: $accounts,
                range: $period->range,
                filters: $filters,
                title: $lineTitle,
                periodLabel: $period->label,
            ),
            DrillKind::BalanceChange => $this->balanceChange(
                accounts: $accounts,
                range: $period->range,
                filters: $filters,
                title: $lineTitle,
                periodLabel: $period->label,
            ),
        };
    }

    /**
     * @return Collection<int, Account>
     */
    private function resolveAccounts(StatementDrillTarget $target): Collection
    {
        if ($target->accountIds === []) {
            return collect();
        }

        $roots = Account::query()->whereIn('id', $target->accountIds)->get();

        if (! $target->subtree) {
            return $roots;
        }

        $accounts = collect();

        foreach ($roots as $root) {
            $accounts = $accounts->merge(
                Account::query()
                    ->subtreeOf($root)
                    ->where('is_postable', true)
                    ->orderBy('code')
                    ->get(),
            );
        }

        return $accounts->unique('id')->sortBy('code')->values();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function periodMovements(
        Collection $accounts,
        DateRange $range,
        ReportFilters $filters,
        string $title,
        string $periodLabel,
    ): DrillDownResult {
        $from = $range->start ?? CarbonImmutable::parse(self::EPOCH);
        $to = $range->end;

        $rows = $this->collectMovements($accounts, $from, $to, $filters);

        [$debit, $credit] = $this->sumMovements($rows);

        return new DrillDownResult(
            title: $title,
            periodLabel: $periodLabel,
            kind: DrillKind::PeriodMovements,
            rows: $rows,
            filters: $filters,
            isFiltered: $filters->narrowsLines(),
            periodDebit: $debit,
            periodCredit: $credit,
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function cumulativeBalance(
        Collection $accounts,
        DateRange $range,
        ReportFilters $filters,
        string $title,
        string $periodLabel,
    ): DrillDownResult {
        $to = $range->end;
        $rows = $this->collectMovements($accounts, CarbonImmutable::parse(self::EPOCH), $to, $filters);

        return new DrillDownResult(
            title: $title,
            periodLabel: $periodLabel,
            kind: DrillKind::CumulativeBalance,
            rows: $rows,
            filters: $filters,
            isFiltered: $filters->narrowsLines(),
            closing: $this->signedTotal($accounts, DateRange::upTo($to), $filters),
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function balanceChange(
        Collection $accounts,
        DateRange $range,
        ReportFilters $filters,
        string $title,
        string $periodLabel,
    ): DrillDownResult {
        $start = $range->start;

        if ($start === null) {
            return $this->periodMovements($accounts, $range, $filters, $title, $periodLabel);
        }

        $rows = $this->collectMovements($accounts, $start, $range->end, $filters);

        [$debit, $credit] = $this->sumMovements($rows);

        return new DrillDownResult(
            title: $title,
            periodLabel: $periodLabel,
            kind: DrillKind::BalanceChange,
            rows: $rows,
            filters: $filters,
            isFiltered: $filters->narrowsLines(),
            opening: $this->signedTotal($accounts, DateRange::endingBefore($start), $filters),
            closing: $this->signedTotal($accounts, DateRange::upTo($range->end), $filters),
            periodDebit: $debit,
            periodCredit: $credit,
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, DrillMovementRow>
     */
    private function collectMovements(
        Collection $accounts,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ReportFilters $filters,
    ): Collection {
        if ($accounts->isEmpty()) {
            return collect();
        }

        if ($accounts->count() === 1) {
            $account = $accounts->first();

            return $this->ledger->movements($account, $from, $to, $filters)
                ->map(fn (LedgerMovement $movement): DrillMovementRow => DrillMovementRow::fromLedgerMovement($movement));
        }

        $rows = collect();

        foreach ($accounts as $account) {
            foreach ($this->ledger->movements($account, $from, $to, $filters) as $movement) {
                $rows->push(DrillMovementRow::fromLedgerMovement($movement, $account->displayName()));
            }
        }

        return $rows->sortBy([
            fn (DrillMovementRow $row): int => $row->date->getTimestamp(),
            fn (DrillMovementRow $row): string => $row->number,
        ])->values();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function signedTotal(Collection $accounts, DateRange $range, ReportFilters $filters): string
    {
        if ($range->isEmpty() || $accounts->isEmpty()) {
            return $this->zero();
        }

        $totals = $this->balances->perAccount($range, $filters);
        $sum = $this->zero();

        foreach ($accounts as $account) {
            $id = $account->getKey();
            $debit = $totals[$id]['debit'] ?? '0';
            $credit = $totals[$id]['credit'] ?? '0';

            $signed = $account->normalBalance() === NormalBalance::Debit
                ? bcsub($debit, $credit, self::SCALE)
                : bcsub($credit, $debit, self::SCALE);

            $sum = bcadd($sum, $signed, self::SCALE);
        }

        return $sum;
    }

    /**
     * @param  Collection<int, DrillMovementRow>  $rows
     * @return array{string, string}
     */
    private function sumMovements(Collection $rows): array
    {
        $debit = $this->zero();
        $credit = $this->zero();

        foreach ($rows as $row) {
            $debit = bcadd($debit, $row->debit, self::SCALE);
            $credit = bcadd($credit, $row->credit, self::SCALE);
        }

        return [$debit, $credit];
    }

    private function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }
}
