<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Resolves a statement row's drill target into ledger movements or a breakdown.
 *
 * Composite paths read figures from the statement that was already built for the
 * same filters and column, so the panel explains a number rather than reaching
 * for a second calculation path.
 */
final class StatementDrillDown
{
    private const SCALE = 4;

    private const EPOCH = '1970-01-01';

    public function __construct(
        private readonly GeneralLedger $ledger,
        private readonly LedgerBalances $balances,
        private readonly IncomeStatement $incomeStatement,
        private readonly BalanceSheet $balanceSheet,
    ) {}

    public function execute(
        StatementDrillTarget $target,
        FinancialStatementDrillContext $context,
        string $lineTitle,
    ): DrillDownResult {
        return match ($target->kind) {
            DrillKind::PeriodMovements => $this->periodMovements(
                accounts: $this->resolveAccounts($target),
                range: $this->resolveDateRange(DrillDateWindow::Period, $context),
                filters: $context->filters,
                title: $lineTitle,
                periodLabel: $context->period->label,
            ),
            DrillKind::CumulativeBalance => $this->cumulativeBalance(
                accounts: $this->resolveAccounts($target),
                range: $this->resolveDateRange(DrillDateWindow::Period, $context),
                filters: $context->filters,
                title: $lineTitle,
                periodLabel: $context->period->label,
            ),
            DrillKind::BalanceChange => $this->balanceChange(
                accounts: $this->resolveAccounts($target),
                range: $this->resolveDateRange(DrillDateWindow::Period, $context),
                filters: $context->filters,
                title: $lineTitle,
                periodLabel: $context->period->label,
            ),
            DrillKind::Composite => $this->composite(
                target: $target,
                context: $context,
                title: $lineTitle,
            ),
            DrillKind::SectionBreakdown => $this->sectionBreakdown(
                context: $context,
                title: $lineTitle,
                sectionKey: $target->sectionKey ?? '',
                atPeriodOpening: $target->atPeriodOpening,
            ),
        };
    }

    private function composite(
        StatementDrillTarget $target,
        FinancialStatementDrillContext $context,
        string $title,
    ): DrillDownResult {
        $rows = collect();
        $total = $this->zero();

        foreach ($target->parts as $part) {
            $raw = $this->resolveReferenceAmount($part->reference, $context);
            $signed = $this->applySign($raw, $part->sign);

            $rows->push(new DrillBreakdownRow(
                label: $part->label,
                signedAmount: $signed,
                sign: $part->sign,
                nestedTarget: $this->nestedTargetFor($part->reference),
            ));

            $total = bcadd($total, $signed, self::SCALE);
        }

        return new DrillDownResult(
            title: $title,
            periodLabel: $context->period->label,
            kind: DrillKind::Composite,
            rows: collect(),
            filters: $context->filters,
            isFiltered: $context->filters->narrowsLines(),
            breakdownRows: $rows,
            total: $total,
        );
    }

    private function sectionBreakdown(
        FinancialStatementDrillContext $context,
        string $title,
        string $sectionKey,
        bool $atPeriodOpening = false,
    ): DrillDownResult {
        $statement = $atPeriodOpening
            ? $this->balanceSheetAtPeriodOpening($context)
            : $context->statement;

        $section = $this->findSection($statement, $sectionKey)
            ?? $this->findSection($this->incomeStatementFor($context), $sectionKey);
        $rows = collect();
        $total = $this->zero();

        if ($section !== null) {
            foreach ($section->lines as $line) {
                $amount = $line->amounts[$context->columnIndex] ?? $this->zero();

                if (bccomp($amount, '0', self::SCALE) === 0) {
                    continue;
                }

                $rows->push(new DrillBreakdownRow(
                    label: $line->name,
                    signedAmount: $amount,
                    sign: 1,
                    nestedTarget: $line->drill,
                ));

                $total = bcadd($total, $amount, self::SCALE);
            }
        }

        return new DrillDownResult(
            title: $title,
            periodLabel: $context->period->label,
            kind: DrillKind::SectionBreakdown,
            rows: collect(),
            filters: $context->filters,
            isFiltered: $context->filters->narrowsLines(),
            breakdownRows: $rows,
            total: $total,
        );
    }

    private function resolveReferenceAmount(
        StatementDrillReference $reference,
        FinancialStatementDrillContext $context,
    ): string {
        if ($reference->sectionKey !== null) {
            return $this->sectionTotal($context->statement, $reference->sectionKey, $context->columnIndex);
        }

        if ($reference->summaryKey !== null) {
            return $this->summaryTotal($context->statement, $reference->summaryKey, $context->columnIndex);
        }

        if ($reference->incomeSectionKey !== null) {
            return $this->sectionTotal(
                $this->incomeStatementFor($context),
                $reference->incomeSectionKey,
                $context->columnIndex,
            );
        }

        if ($reference->incomeSummaryKey !== null) {
            return $this->summaryTotal(
                $this->incomeStatementFor($context),
                $reference->incomeSummaryKey,
                $context->columnIndex,
            );
        }

        if ($reference->ledger !== null) {
            return $this->ledgerAmount($reference->ledger, $context, $reference->dateWindow);
        }

        if ($reference->accountType !== null && $reference->dateWindow !== null) {
            return $this->accountTypeAmount($reference->accountType, $reference->dateWindow, $context);
        }

        return $this->zero();
    }

    private function nestedTargetFor(StatementDrillReference $reference): ?StatementDrillTarget
    {
        if ($reference->ledger !== null) {
            return $reference->ledger;
        }

        if ($reference->incomeSummaryKey !== null) {
            return match ($reference->incomeSummaryKey) {
                'gross_profit' => StatementDrillTargets::grossProfit(),
                'operating_result' => StatementDrillTargets::operatingResult(),
                'net_profit' => StatementDrillTargets::netProfit(),
                default => null,
            };
        }

        if ($reference->incomeSectionKey !== null) {
            return StatementDrillTarget::sectionBreakdown($reference->incomeSectionKey);
        }

        if ($reference->sectionKey !== null) {
            return StatementDrillTarget::sectionBreakdown($reference->sectionKey);
        }

        if ($reference->summaryKey !== null) {
            return match ($reference->summaryKey) {
                'gross_profit' => StatementDrillTargets::grossProfit(),
                'operating_result' => StatementDrillTargets::operatingResult(),
                'net_profit' => StatementDrillTargets::netProfit(),
                'net_change' => StatementDrillTargets::netCashChange(),
                'cash_closing' => StatementDrillTargets::cashClosing(),
                'cash_opening' => StatementDrillTargets::cashOpening(),
                'equity_opening' => StatementDrillTargets::equityOpening(),
                'liabilities_and_equity' => StatementDrillTargets::liabilitiesAndEquity(),
                'equity_closing' => StatementDrillTargets::equityClosing(),
                default => null,
            };
        }

        return null;
    }

    private function incomeStatementFor(FinancialStatementDrillContext $context): FinancialStatement
    {
        if ($context->from === null || $context->to === null) {
            return $context->statement;
        }

        return $this->incomeStatement->build(
            from: $context->from,
            to: $context->to,
            options: $context->options,
        );
    }

    private function ledgerAmount(
        StatementDrillTarget $target,
        FinancialStatementDrillContext $context,
        ?DrillDateWindow $dateWindow = null,
    ): string {
        $accounts = $this->resolveAccounts($target);
        $range = $this->resolveDateRange($dateWindow ?? DrillDateWindow::Period, $context);

        return match ($target->kind) {
            DrillKind::PeriodMovements => $this->periodMovementTotal($accounts, $range, $context->filters),
            DrillKind::BalanceChange => $this->balanceChangeTotal($accounts, $range, $context->filters),
            DrillKind::CumulativeBalance => $this->signedTotal($accounts, DateRange::upTo($range->end), $context->filters),
            default => $this->zero(),
        };
    }

    private function accountTypeAmount(
        AccountType $type,
        DrillDateWindow $dateWindow,
        FinancialStatementDrillContext $context,
    ): string {
        $range = $this->resolveDateRange($dateWindow, $context);

        return $this->balanceSheet->typedTotal($type, $range, $context->filters);
    }

    private function resolveDateRange(
        DrillDateWindow $window,
        FinancialStatementDrillContext $context,
    ): DateRange {
        $asOf = $context->period->range->end;

        return match ($window) {
            DrillDateWindow::Period => $context->period->range,
            DrillDateWindow::BeforePeriodStart => $this->openingRange($context),
            DrillDateWindow::BeforeFiscalYearStart => $this->balanceSheet->broughtForwardRange($asOf),
            DrillDateWindow::FiscalYearToPeriodEnd => $this->balanceSheet->currentResultRange($asOf),
        };
    }

    private function openingRange(FinancialStatementDrillContext $context): DateRange
    {
        $start = $context->period->range->start;

        if ($start === null) {
            return DateRange::upTo($context->period->range->end);
        }

        return DateRange::endingBefore($start);
    }

    private function balanceSheetAtPeriodOpening(FinancialStatementDrillContext $context): FinancialStatement
    {
        $opening = $this->openingRange($context);

        return $this->balanceSheet->build(
            asOf: $opening->end,
            options: new StatementOptions(
                filters: $context->filters,
                interval: $context->options->interval,
                comparisons: 0,
                depth: $context->options->depth,
                includeEmpty: $context->options->includeEmpty,
            ),
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function periodMovementTotal(Collection $accounts, DateRange $range, ReportFilters $filters): string
    {
        if ($accounts->isEmpty() || $range->isEmpty()) {
            return $this->zero();
        }

        $totals = $this->balances->perAccount($range, $filters);
        $sum = $this->zero();

        foreach ($accounts as $account) {
            $id = $account->getKey();
            $debit = $totals[$id]['debit'] ?? '0';
            $credit = $totals[$id]['credit'] ?? '0';

            $movement = $account->normalBalance() === NormalBalance::Debit
                ? bcsub($debit, $credit, self::SCALE)
                : bcsub($credit, $debit, self::SCALE);

            $sum = bcadd($sum, $movement, self::SCALE);
        }

        return $sum;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function balanceChangeTotal(Collection $accounts, DateRange $range, ReportFilters $filters): string
    {
        $start = $range->start;

        if ($start === null || $accounts->isEmpty()) {
            return $this->zero();
        }

        $opening = $this->signedTotal($accounts, DateRange::endingBefore($start), $filters);
        $closing = $this->signedTotal($accounts, DateRange::upTo($range->end), $filters);

        return bcsub($closing, $opening, self::SCALE);
    }

    private function sectionTotal(FinancialStatement $statement, string $key, int $columnIndex): string
    {
        $section = $this->findSection($statement, $key);

        return $section?->totals[$columnIndex] ?? $this->zero();
    }

    private function summaryTotal(FinancialStatement $statement, string $key, int $columnIndex): string
    {
        foreach ($statement->sections as $section) {
            if ($section->isSummary && $section->key === $key) {
                return $section->totals[$columnIndex] ?? $this->zero();
            }
        }

        return $this->zero();
    }

    private function findSection(FinancialStatement $statement, string $key): ?StatementSection
    {
        foreach ($statement->sections as $section) {
            if ($section->key === $key && ! $section->isSummary) {
                return $section;
            }
        }

        return null;
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
            breakdownRows: collect(),
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
            breakdownRows: collect(),
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
            breakdownRows: collect(),
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

    private function applySign(string $amount, int $sign): string
    {
        if ($sign >= 0) {
            return $amount;
        }

        return bccomp($amount, '0', self::SCALE) === 0
            ? $amount
            : bcsub('0', $amount, self::SCALE);
    }

    private function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }
}
