<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\NormalBalance;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Services\Accounting\AccountRegistry;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The cash flow statement, indirect method.
 *
 * Qoyod builds this from net income before interest, tax and zakat, then walks
 * back non-cash items and working-capital movements to explain how much cash
 * operating activity actually produced. Investing and financing sections read
 * the change in the relevant balance-sheet accounts across the same window.
 * The closing cash line must agree with the cash and bank accounts on the
 * balance sheet — if it does not, something is misclassified in the chart.
 *
 * Accrual throughout, as the income statement is: an invoice counts on issue,
 * not on collection. Operating cash flow therefore diverges from net profit
 * whenever receivables or inventory move, which is exactly what this report
 * is for.
 */
final class CashFlowStatement
{
    private const SCALE = 4;

    /** @var list<string> */
    private const CASH_CODES = ['1110', '1120'];

    /** @var list<string> */
    private const WORKING_CAPITAL_CODES = ['1160', '2130'];

    public function __construct(
        private readonly LedgerBalances $balances,
        private readonly IncomeStatement $incomeStatement,
        private readonly AccountRegistry $registry,
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
        $operatingResult = $this->sectionTotals($income, 'operating_result');

        $depreciation = $this->periodExpenseOnRole(SystemAccount::DepreciationExpense, $periods, $options->filters);
        $lossOnDisposal = $this->periodExpenseOnRole(SystemAccount::LossOnAssetDisposal, $periods, $options->filters);
        $gainOnDisposal = $this->periodIncomeOnRole(SystemAccount::GainOnAssetDisposal, $periods, $options->filters);
        $interestTaxZakatPaid = $this->interestTaxAndZakatPaid($periods, $options->filters);

        $workingCapitalLines = $this->workingCapitalLines($periods, $options);

        $operatingLines = [
            StatementLine::derived(
                __('accounting.statements.lines.operating_result'),
                $operatingResult,
                drill: StatementDrillTargets::operatingResult(),
            ),
            $this->derivedFromRole(
                SystemAccount::DepreciationExpense,
                $depreciation,
                DrillKind::PeriodMovements,
                __('accounting.statements.lines.depreciation'),
            ),
        ];

        if ($this->hasNonZero($lossOnDisposal)) {
            $operatingLines[] = $this->derivedFromRole(
                SystemAccount::LossOnAssetDisposal,
                $lossOnDisposal,
                DrillKind::PeriodMovements,
                __('accounting.statements.lines.loss_on_disposal'),
            );
        }

        if ($this->hasNonZero($gainOnDisposal)) {
            $operatingLines[] = $this->derivedFromRole(
                SystemAccount::GainOnAssetDisposal,
                $this->negate($gainOnDisposal),
                DrillKind::PeriodMovements,
                __('accounting.statements.lines.gain_on_disposal'),
            );
        }

        foreach ($workingCapitalLines as $line) {
            $operatingLines[] = $line;
        }

        if ($this->hasNonZero($interestTaxZakatPaid)) {
            $operatingLines[] = StatementLine::derived(
                __('accounting.statements.lines.interest_tax_zakat_paid'),
                $this->negate($interestTaxZakatPaid),
                drill: StatementDrillTargets::interestTaxZakatPaidOnCashFlow($this->registry),
            );
        }

        $operatingTotal = $this->sum([
            $operatingResult,
            $depreciation,
            $lossOnDisposal,
            $this->negate($gainOnDisposal),
            $this->lineAmounts($workingCapitalLines),
            $this->negate($interestTaxZakatPaid),
        ]);

        $investingLines = $this->investingLines($periods, $options);
        $investingTotal = $investingLines === []
            ? $this->zeros($columns)
            : $this->lineAmounts($investingLines);

        $financingLines = $this->financingLines($periods, $options);
        $financingTotal = $financingLines === []
            ? $this->zeros($columns)
            : $this->lineAmounts($financingLines);

        $netChange = $this->sum([$operatingTotal, $investingTotal, $financingTotal]);
        $cashOpening = $this->cashBalanceAtPeriodOpens($periods, $options->filters);
        $cashClosing = $this->add($cashOpening, $netChange);
        $ledgerCashClosing = $this->cashBalanceAtPeriodEnds($periods, $options->filters);

        $reconciliation = $this->subtract($cashClosing, $ledgerCashClosing);

        return new FinancialStatement(
            periods: $periods,
            sections: [
                new StatementSection(
                    key: 'operating',
                    lines: $operatingLines,
                    totals: $operatingTotal,
                    drill: StatementDrillTarget::sectionBreakdown('operating'),
                ),
                new StatementSection(
                    key: 'investing',
                    lines: $investingLines,
                    totals: $investingTotal,
                    drill: StatementDrillTarget::sectionBreakdown('investing'),
                ),
                new StatementSection(
                    key: 'financing',
                    lines: $financingLines,
                    totals: $financingTotal,
                    drill: StatementDrillTarget::sectionBreakdown('financing'),
                ),
                StatementSection::summary('net_change', $netChange, drill: StatementDrillTargets::netCashChange()),
                StatementSection::summary('cash_opening', $cashOpening),
                StatementSection::summary('cash_closing', $cashClosing, emphasised: true, drill: StatementDrillTargets::cashClosing()),
            ],
            isFiltered: $options->filters->narrowsLines(),
            imbalance: $options->filters->narrowsLines() ? null : $reconciliation,
            imbalanceMessage: 'accounting.statements.cash_flow_out_of_balance',
        );
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<StatementLine>
     */
    private function workingCapitalLines(array $periods, StatementOptions $options): array
    {
        $lines = [];

        foreach ($this->workingCapitalAccounts() as $account) {
            $amounts = $this->workingCapitalEffect($account, $periods, $options->filters);

            if (! $options->includeEmpty && ! $this->hasNonZero($amounts)) {
                continue;
            }

            $lines[] = $this->derivedFromAccount(
                $account,
                $amounts,
                DrillKind::BalanceChange,
            );
        }

        return $lines;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<StatementLine>
     */
    private function investingLines(array $periods, StatementOptions $options): array
    {
        $lines = [];
        $fixedAssets = $this->registry->find(SystemAccount::FixedAssets);

        if ($fixedAssets !== null) {
            $amounts = $this->negate($this->balanceChanges($fixedAssets, $periods, $options->filters));

            if ($options->includeEmpty || $this->hasNonZero($amounts)) {
                $lines[] = StatementLine::derived(
                    __('accounting.statements.lines.fixed_assets'),
                    $amounts,
                    drill: StatementDrillTarget::subtree(DrillKind::BalanceChange, $fixedAssets->getKey()),
                );
            }
        }

        $intangible = Account::query()->where('code', '1230')->first();

        if ($intangible !== null) {
            $amounts = $this->negate($this->balanceChanges($intangible, $periods, $options->filters));

            if ($options->includeEmpty || $this->hasNonZero($amounts)) {
                $lines[] = $this->derivedFromAccount(
                    $intangible,
                    $amounts,
                    DrillKind::BalanceChange,
                );
            }
        }

        return $lines;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<StatementLine>
     */
    private function financingLines(array $periods, StatementOptions $options): array
    {
        $lines = [];

        foreach ($this->financingAccounts() as $account) {
            $change = $this->balanceChanges($account, $periods, $options->filters);
            $amounts = $account->type->normalBalance() === NormalBalance::Credit
                ? $change
                : $this->negate($change);

            if (! $options->includeEmpty && ! $this->hasNonZero($amounts)) {
                continue;
            }

            $lines[] = $this->derivedFromAccount(
                $account,
                $amounts,
                DrillKind::BalanceChange,
            );
        }

        return $lines;
    }

    /**
     * Cash paid for interest, tax and zakat in the period.
     *
     * Expense recognised below the operating result, minus any amount that
     * landed in payables rather than leaving the bank.
     *
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function interestTaxAndZakatPaid(array $periods, ReportFilters $filters): array
    {
        $expense = $this->periodExpenseOnSubtree(SystemAccount::InterestTaxAndZakat, $periods, $filters);
        $zakatPayable = $this->registry->find(SystemAccount::ZakatPayable);

        if ($zakatPayable === null) {
            return $expense;
        }

        $payableChange = $this->balanceChanges($zakatPayable, $periods, $filters);

        return $this->subtract($expense, $payableChange);
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function periodExpenseOnRole(
        SystemAccount $role,
        array $periods,
        ReportFilters $filters,
    ): array {
        $account = $this->registry->find($role);

        if ($account === null) {
            return $this->zeros(count($periods));
        }

        return $this->periodMovement($account, $periods, $filters, debitNormal: true);
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function periodIncomeOnRole(
        SystemAccount $role,
        array $periods,
        ReportFilters $filters,
    ): array {
        $account = $this->registry->find($role);

        if ($account === null) {
            return $this->zeros(count($periods));
        }

        return $this->periodMovement($account, $periods, $filters, debitNormal: false);
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function periodExpenseOnSubtree(
        SystemAccount $role,
        array $periods,
        ReportFilters $filters,
    ): array {
        $accounts = $this->subtreeOf($role);

        if ($accounts === []) {
            return $this->zeros(count($periods));
        }

        $totals = $this->zeros(count($periods));

        foreach ($accounts as $account) {
            $totals = $this->add(
                $totals,
                $this->periodMovement($account, $periods, $filters, debitNormal: true),
            );
        }

        return $totals;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function workingCapitalEffect(
        Account $account,
        array $periods,
        ReportFilters $filters,
    ): array {
        $change = $this->balanceChanges($account, $periods, $filters);

        return $account->type->normalBalance() === NormalBalance::Debit
            ? $this->negate($change)
            : $change;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function balanceChanges(
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

            $opening = $this->signedBalance($account, DateRange::endingBefore($start), $filters);
            $closing = $this->signedBalance($account, DateRange::upTo($period->range->end), $filters);
            $amounts[] = bcsub($closing, $opening, self::SCALE);
        }

        return $amounts;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function periodMovement(
        Account $account,
        array $periods,
        ReportFilters $filters,
        bool $debitNormal,
    ): array {
        $amounts = [];

        foreach ($periods as $period) {
            $totals = $this->balances->perAccount($period->range, $filters);
            $id = $account->getKey();
            $debit = $totals[$id]['debit'] ?? '0';
            $credit = $totals[$id]['credit'] ?? '0';

            $amounts[] = $debitNormal
                ? bcsub($debit, $credit, self::SCALE)
                : bcsub($credit, $debit, self::SCALE);
        }

        return $amounts;
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
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function cashBalanceAtPeriodOpens(array $periods, ReportFilters $filters): array
    {
        $amounts = [];

        foreach ($periods as $period) {
            $start = $period->range->start;

            if ($start === null) {
                $amounts[] = $this->zero();

                continue;
            }

            $amounts[] = $this->cashBalance(DateRange::endingBefore($start), $filters);
        }

        return $amounts;
    }

    /**
     * @param  list<StatementPeriod>  $periods
     * @return list<string>
     */
    private function cashBalanceAtPeriodEnds(array $periods, ReportFilters $filters): array
    {
        $amounts = [];

        foreach ($periods as $period) {
            $amounts[] = $this->cashBalance(DateRange::upTo($period->range->end), $filters);
        }

        return $amounts;
    }

    private function cashBalance(DateRange $range, ReportFilters $filters): string
    {
        $total = $this->zero();

        foreach ($this->cashAccounts() as $account) {
            $total = bcadd($total, $this->signedBalance($account, $range, $filters), self::SCALE);
        }

        return $total;
    }

    /**
     * @return Collection<int, Account>
     */
    private function workingCapitalAccounts(): Collection
    {
        $roles = [
            SystemAccount::AccountsReceivable,
            SystemAccount::Inventory,
            SystemAccount::VatInputRecoverable,
            SystemAccount::SupplierAdvances,
            SystemAccount::EmployeeAdvances,
            SystemAccount::AccountsPayable,
            SystemAccount::VatOutputPayable,
            SystemAccount::SalariesPayable,
            SystemAccount::GosiPayable,
            SystemAccount::CustomerAdvances,
        ];

        $accounts = collect();

        foreach ($roles as $role) {
            $account = $this->registry->find($role);

            if ($account !== null) {
                $accounts->push($account);
            }
        }

        foreach (self::WORKING_CAPITAL_CODES as $code) {
            $account = Account::query()->where('code', $code)->first();

            if ($account !== null) {
                $accounts->push($account);
            }
        }

        return $accounts->sortBy('code')->values();
    }

    /**
     * @return list<Account>
     */
    private function financingAccounts(): array
    {
        $accounts = [];

        foreach (['3100', '3400', '2210'] as $code) {
            $account = Account::query()->where('code', $code)->first();

            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * @return list<Account>
     */
    private function cashAccounts(): array
    {
        return Account::query()
            ->whereIn('code', self::CASH_CODES)
            ->orderBy('code')
            ->get()
            ->all();
    }

    /**
     * @return list<Account>
     */
    private function subtreeOf(SystemAccount $role): array
    {
        $root = $this->registry->find($role);

        if ($root === null || $root->path === null) {
            return [];
        }

        $prefix = addcslashes($root->path, '%_\\');

        return Account::query()
            ->where(function ($query) use ($root, $prefix): void {
                $query->whereKey($root->getKey())
                    ->orWhere('path', 'like', $prefix.'.%');
            })
            ->get()
            ->all();
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

    /**
     * @param  list<string>  $amounts
     */
    private function derivedFromAccount(Account $account, array $amounts, DrillKind $kind): StatementLine
    {
        return StatementLine::derived(
            name: $this->accountName($account),
            amounts: $amounts,
            drill: StatementDrillTarget::account($kind, $account->getKey()),
            accountId: $account->getKey(),
            code: $account->code,
        );
    }

    /**
     * @param  list<string>  $amounts
     */
    private function derivedFromRole(
        SystemAccount $role,
        array $amounts,
        DrillKind $kind,
        string $name,
    ): StatementLine {
        $account = $this->registry->find($role);

        if ($account === null) {
            return StatementLine::derived($name, $amounts);
        }

        return StatementLine::derived(
            name: $name,
            amounts: $amounts,
            drill: StatementDrillTarget::account($kind, $account->getKey()),
            accountId: $account->getKey(),
            code: $account->code,
        );
    }
}
