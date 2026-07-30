<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\JournalEntryStatus;
use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The trial balance.
 *
 * Lists every account with its opening balance, movement in the period and
 * closing balance. Its purpose is singular: if total debits do not equal total
 * credits, the ledger is broken, and every statement derived from it is wrong.
 *
 * Computed directly from `journal_entry_lines` — no stored balances, so the
 * report cannot drift from the entries it summarises. Only posted entries are
 * included; drafts are working material and a trial balance containing them
 * would not be one.
 */
final class TrialBalance
{
    private const SCALE = 4;

    /**
     * Build the report.
     *
     * @param  array{branch_id?: string|null, dimension_value_id?: string|null}  $filters
     * @return Collection<int, TrialBalanceRow>
     */
    public function build(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $filters = [],
        bool $includeEmpty = false,
    ): Collection {
        $opening = $this->aggregate(null, $from, $filters, exclusiveEnd: true);
        $period = $this->aggregate($from, $to, $filters);

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
     * Debit and credit sums per account over a date window.
     *
     * One grouped query rather than a query per account: a chart of a few
     * hundred accounts would otherwise mean a few hundred round trips for a
     * report that is opened constantly.
     *
     * @param  array{branch_id?: string|null, dimension_value_id?: string|null}  $filters
     * @return array<string, array{debit: string, credit: string}>
     */
    private function aggregate(
        ?DateTimeInterface $from,
        DateTimeInterface $to,
        array $filters,
        bool $exclusiveEnd = false,
    ): array {
        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->companyId())
            // Drafts never contribute to a ledger figure.
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->groupBy('l.account_id')
            ->select([
                'l.account_id',
                DB::raw('SUM(l.debit) as debit_total'),
                DB::raw('SUM(l.credit) as credit_total'),
            ]);

        if ($from !== null) {
            $query->whereDate('e.entry_date', '>=', $from);
        }

        // Opening balances are everything strictly before the window opens.
        $exclusiveEnd
            ? $query->whereDate('e.entry_date', '<', $to)
            : $query->whereDate('e.entry_date', '<=', $to);

        if (filled($filters['branch_id'] ?? null)) {
            $query->where('l.branch_id', $filters['branch_id']);
        }

        if (filled($filters['dimension_value_id'] ?? null)) {
            // Dimension tags live in their own table, so the filter is an
            // existence check rather than a column comparison.
            $query->whereExists(function ($sub) use ($filters): void {
                $sub->selectRaw('1')
                    ->from('journal_entry_line_dimensions as d')
                    ->whereColumn('d.journal_entry_line_id', 'l.id')
                    ->where('d.dimension_value_id', $filters['dimension_value_id']);
            });
        }

        $results = [];

        foreach ($query->get() as $row) {
            $results[$row->account_id] = [
                'debit' => $this->scale((string) $row->debit_total),
                'credit' => $this->scale((string) $row->credit_total),
            ];
        }

        return $results;
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

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }

    private function companyId(): string
    {
        return app(\App\Support\Tenancy\CompanyContext::class)->idOrFail();
    }
}
