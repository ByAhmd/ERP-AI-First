<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The trial balance.
 *
 * Lists every account with its opening balance, movement in the period and
 * closing balance. Its purpose is singular: if total debits do not equal total
 * credits, the ledger is broken, and every statement derived from it is wrong.
 *
 * Computed directly from the ledger through {@see LedgerBalances} — no stored
 * balances, so the report cannot drift from the entries it summarises, and no
 * private opinion about which entries count.
 */
final class TrialBalance
{
    private const SCALE = 4;

    public function __construct(
        private readonly LedgerBalances $balances,
    ) {}

    /**
     * Build the report.
     *
     * @return Collection<int, TrialBalanceRow>
     */
    public function build(
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?ReportFilters $filters = null,
        bool $includeEmpty = false,
    ): Collection {
        $filters ??= ReportFilters::none();

        $opening = $this->balances->perAccount(DateRange::endingBefore($from), $filters);
        $period = $this->balances->perAccount(DateRange::between($from, $to), $filters);

        $accountIds = array_unique([...array_keys($opening), ...array_keys($period)]);

        $accounts = Account::query()
            ->when(! $includeEmpty, fn ($query) => $query->whereKey($accountIds))
            ->orderBy('code')
            ->get();

        return $accounts
            ->map(fn (Account $account): TrialBalanceRow => $this->row($account, $opening, $period))
            ->when(
                ! $includeEmpty,
                fn (Collection $rows): Collection => $rows->filter(
                    static fn (TrialBalanceRow $row): bool => $row->hasBalance() || $row->hasActivity(),
                ),
            )
            ->values();
    }

    /**
     * Totals for a built report.
     *
     * @param  Collection<int, TrialBalanceRow>  $rows
     * @return array{opening_debit: string, opening_credit: string, period_debit: string, period_credit: string, closing_debit: string, closing_credit: string, balanced: bool}
     */
    public function totals(Collection $rows): array
    {
        // Seeded at full scale so an empty report returns "0.0000" like every
        // other figure, rather than the unscaled seed reduce() hands back when
        // there is nothing to fold over.
        $sum = static fn (string $field): string => $rows->reduce(
            static fn (string $carry, TrialBalanceRow $row): string => bcadd($carry, $row->{$field}, self::SCALE),
            bcadd('0', '0', self::SCALE),
        );

        $closingDebit = $sum('closingDebit');
        $closingCredit = $sum('closingCredit');

        return [
            'opening_debit' => $sum('openingDebit'),
            'opening_credit' => $sum('openingCredit'),
            'period_debit' => $sum('periodDebit'),
            'period_credit' => $sum('periodCredit'),
            'closing_debit' => $closingDebit,
            'closing_credit' => $closingCredit,
            // The assertion the whole report exists to make.
            'balanced' => bccomp($closingDebit, $closingCredit, self::SCALE) === 0,
        ];
    }

    /**
     * @param  array<string, array{debit: string, credit: string}>  $opening
     * @param  array<string, array{debit: string, credit: string}>  $period
     */
    private function row(Account $account, array $opening, array $period): TrialBalanceRow
    {
        $id = $account->getKey();

        $openingDebit = $opening[$id]['debit'] ?? '0';
        $openingCredit = $opening[$id]['credit'] ?? '0';
        $periodDebit = $period[$id]['debit'] ?? '0';
        $periodCredit = $period[$id]['credit'] ?? '0';

        $closingDebit = bcadd($openingDebit, $periodDebit, self::SCALE);
        $closingCredit = bcadd($openingCredit, $periodCredit, self::SCALE);

        // Presented net, on whichever side the account actually sits. Showing
        // both raw columns would make a bank account that has been paid into
        // and out of look like twice the activity it had.
        $net = bcsub($closingDebit, $closingCredit, self::SCALE);

        return new TrialBalanceRow(
            accountId: $id,
            code: $account->code,
            // Name only. displayName() prefixes the code, which this report
            // already carries in its own column.
            name: app()->getLocale() === 'en' && filled($account->name_en)
                ? $account->name_en
                : $account->name,
            type: $account->type,
            openingDebit: $this->netDebit($openingDebit, $openingCredit),
            openingCredit: $this->netCredit($openingDebit, $openingCredit),
            periodDebit: $periodDebit,
            periodCredit: $periodCredit,
            closingDebit: bccomp($net, '0', self::SCALE) > 0 ? $net : '0.0000',
            closingCredit: bccomp($net, '0', self::SCALE) < 0 ? bcmul($net, '-1', self::SCALE) : '0.0000',
        );
    }

    private function netDebit(string $debit, string $credit): string
    {
        $net = bcsub($debit, $credit, self::SCALE);

        return bccomp($net, '0', self::SCALE) > 0 ? $net : '0.0000';
    }

    private function netCredit(string $debit, string $credit): string
    {
        $net = bcsub($debit, $credit, self::SCALE);

        return bccomp($net, '0', self::SCALE) < 0 ? bcmul($net, '-1', self::SCALE) : '0.0000';
    }
}
