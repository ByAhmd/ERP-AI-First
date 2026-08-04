<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Support\Tenancy\CompanyContext;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The general ledger for one account.
 *
 * Every movement in a period, in date order, with the balance after each. Where
 * the trial balance answers "does the ledger balance", this answers "how did
 * this account get to its figure" — the report reached for when reconciling a
 * bank account or explaining a balance to an auditor.
 *
 * Opening balance is carried in from everything before the window, so the
 * closing figure here ties to the trial balance for the same date. If the two
 * ever disagree, one of them is computing from something other than the ledger.
 */
final class GeneralLedger
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * Movements for an account across a period.
     *
     * @return Collection<int, LedgerMovement>
     */
    public function movements(
        Account $account,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?ReportFilters $filters = null,
    ): Collection {
        $filters ??= ReportFilters::none();

        $balance = $this->openingBalance($account, $from, $filters);

        $rows = $this->query($account, $filters)
            ->whereDate('e.entry_date', '>=', $from)
            ->whereDate('e.entry_date', '<=', $to)
            // Date first, then entry number: two entries on the same day must
            // appear in the order they were posted, or the running balance
            // reads differently on each refresh.
            ->orderBy('e.entry_date')
            ->orderBy('e.number')
            ->orderBy('l.line_number')
            ->select([
                'l.id as line_id',
                'l.debit',
                'l.credit',
                'l.description as line_description',
                'e.id as entry_id',
                'e.number',
                'e.entry_date',
                'e.description as entry_description',
                'e.reference',
            ])
            ->get();

        return $rows->map(function (object $row) use (&$balance): LedgerMovement {
            $balance = bcadd(
                $balance,
                bcsub((string) $row->debit, (string) $row->credit, self::SCALE),
                self::SCALE,
            );

            return new LedgerMovement(
                entryId: $row->entry_id,
                number: $row->number,
                date: Carbon::parse($row->entry_date),
                // The line's own note is more specific than the entry's, so it
                // wins when present.
                description: $row->line_description ?: $row->entry_description,
                reference: $row->reference,
                debit: $this->scale((string) $row->debit),
                credit: $this->scale((string) $row->credit),
                balance: $balance,
            );
        });
    }

    /**
     * The account's balance immediately before the window opens.
     *
     * Signed, positive for a debit balance.
     */
    public function openingBalance(
        Account $account,
        DateTimeInterface $from,
        ?ReportFilters $filters = null,
    ): string {
        $row = $this->query($account, $filters ?? ReportFilters::none())
            ->whereDate('e.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit_total, COALESCE(SUM(l.credit), 0) as credit_total')
            ->first();

        return bcsub(
            $this->scale((string) ($row->debit_total ?? '0')),
            $this->scale((string) ($row->credit_total ?? '0')),
            self::SCALE,
        );
    }

    /**
     * Totals for the period, and the balance it closes at.
     *
     * @param  Collection<int, LedgerMovement>  $movements
     * @return array{debit: string, credit: string, opening: string, closing: string}
     */
    public function summarise(Collection $movements, string $opening): array
    {
        $debit = $movements->reduce(
            static fn (string $carry, LedgerMovement $m): string => bcadd($carry, $m->debit, self::SCALE),
            bcadd('0', '0', self::SCALE),
        );

        $credit = $movements->reduce(
            static fn (string $carry, LedgerMovement $m): string => bcadd($carry, $m->credit, self::SCALE),
            bcadd('0', '0', self::SCALE),
        );

        return [
            'opening' => $this->scale($opening),
            'debit' => $debit,
            'credit' => $credit,
            'closing' => bcadd($opening, bcsub($debit, $credit, self::SCALE), self::SCALE),
        ];
    }

    /**
     * Present a signed balance the way the account naturally reads.
     *
     * A payable sitting at 5,000 credit is 5,000 owed, not minus 5,000.
     */
    public function inNaturalDirection(Account $account, string $signedBalance): string
    {
        return $account->normalBalance() === NormalBalance::Debit
            ? $signedBalance
            : bcmul($signedBalance, '-1', self::SCALE);
    }

    private function query(Account $account, ReportFilters $filters): Builder
    {
        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->context->idOrFail())
            ->where('l.account_id', $account->getKey())
            // Drafts are working material and never appear in a ledger.
            ->where('e.status', JournalEntryStatus::Posted->value);

        return $filters->applyTo($query);
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
