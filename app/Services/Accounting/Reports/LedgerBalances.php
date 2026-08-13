<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Enums\JournalEntryStatus;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Debit and credit sums read straight from the ledger.
 *
 * Every financial report in the platform ultimately asks this one question, and
 * every one of them must answer it identically: posted entries only, the
 * current company only, within a window, narrowed by whatever the reader chose.
 * Each report used to ask it for itself. That is three chances to disagree
 * about whether drafts count — and a trial balance that includes a line the
 * balance sheet excludes is worse than either report being absent, because both
 * still look authoritative.
 *
 * Amounts come back as scaled strings. The posting engine works in bcmath and a
 * report that returned floats would drift from the entries it summarises.
 */
final class LedgerBalances
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * Totals per account, keyed by account id.
     *
     * One grouped query rather than a query per account: a chart of a few
     * hundred accounts would otherwise mean a few hundred round trips for a
     * report that is opened constantly.
     *
     * @return array<string, array{debit: string, credit: string}>
     */
    public function perAccount(DateRange $range, ReportFilters $filters): array
    {
        if ($range->isEmpty()) {
            return [];
        }

        $rows = $this->query($range, $filters)
            ->groupBy('l.account_id')
            ->select([
                'l.account_id',
                DB::raw('SUM(l.debit) as debit_total'),
                DB::raw('SUM(l.credit) as credit_total'),
            ])
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->account_id] = [
                'debit' => $this->scale((string) $row->debit_total),
                'credit' => $this->scale((string) $row->credit_total),
            ];
        }

        return $totals;
    }

    /**
     * Totals across every account of the given classifications.
     *
     * This is how the balance sheet learns the result for a period without
     * listing a single income account: the accumulated profit that belongs in
     * equity is revenue less expenses, and asking the database to add it up is
     * both cheaper and safer than summing a rendered statement.
     *
     * @param  list<AccountType>  $types
     * @return array{debit: string, credit: string}
     */
    public function forTypes(array $types, DateRange $range, ReportFilters $filters): array
    {
        $zero = ['debit' => $this->scale('0'), 'credit' => $this->scale('0')];

        if ($types === [] || $range->isEmpty()) {
            return $zero;
        }

        $row = $this->query($range, $filters)
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.type', array_map(static fn (AccountType $type): string => $type->value, $types))
            // Statement sections are built through Eloquent, which hides
            // soft-deleted accounts. Counting one here that no section can show
            // would put the balance sheet out by that account's balance.
            // Nothing may delete an account carrying history today, but that is
            // enforced by an observer three classes away and this report should
            // not quietly depend on it.
            ->whereNull('a.deleted_at')
            ->select([
                DB::raw('SUM(l.debit) as debit_total'),
                DB::raw('SUM(l.credit) as credit_total'),
            ])
            ->first();

        if ($row === null || $row->debit_total === null) {
            return $zero;
        }

        return [
            'debit' => $this->scale((string) $row->debit_total),
            'credit' => $this->scale((string) $row->credit_total),
        ];
    }

    /**
     * The base query every reading shares.
     */
    private function query(DateRange $range, ReportFilters $filters): Builder
    {
        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            // Belt and braces: the global scope does not reach the query
            // builder, so tenancy is asserted explicitly on every report query.
            ->where('l.company_id', $this->context->idOrFail())
            // Drafts are working material. A ledger figure that included them
            // would change when someone abandoned an entry they never posted.
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->whereDate('e.entry_date', '<=', $range->end);

        if ($range->start !== null) {
            $query->whereDate('e.entry_date', '>=', $range->start);
        }

        return $filters->applyTo($query);
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
