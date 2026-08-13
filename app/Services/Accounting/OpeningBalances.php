<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\JournalEntryKind;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\Exceptions\OpeningBalanceRejected;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The balances a company arrives with.
 *
 * Every company that moves onto this platform has a history somewhere else, and
 * the first thing it needs is for that history to be true here too: what it
 * owns, what it owes, and what the owners have in it, as at the day it
 * switched. Without this a migrating company cannot use the platform at all —
 * which is why Qoyod sells entering them as a professional service rather than
 * offering a screen for it.
 *
 * Four decisions shape this:
 *
 *  - **Permanent accounts only.** Assets, liabilities and equity carry forward;
 *    revenue and expenses restart each year. A migrating company's past profit
 *    belongs in retained earnings, not in last year's sales account, and
 *    offering an opening balance for revenue would let someone put it there and
 *    inflate this year's income statement.
 *  - **Dated the fiscal year's first day.** The natural date is the day before,
 *    but that falls in a year the company may never have opened, and the
 *    posting gate would refuse it. The first day is inside the year being
 *    opened and is what every accountant writes anyway.
 *  - **Held as a draft until committed.** Opening balances are transcribed from
 *    another system and are wrong on the first pass more often than not. A
 *    draft can be corrected; a posted entry can only be reversed.
 *  - **The difference is visible, never hidden.** What does not balance goes to
 *    the opening balance suspense account, which is what the chart provides it
 *    for. Refusing to save would strand the work; plugging it silently into
 *    equity would misstate the company's own capital.
 *
 * Everything reaches the ledger through {@see JournalPoster}, like every other
 * balance in the platform.
 */
final class OpeningBalances
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
    ) {}

    /**
     * The accounts a company may open a balance on.
     *
     * @return Collection<int, Account>
     */
    public function eligibleAccounts(): Collection
    {
        $suspense = $this->registry->find(SystemAccount::OpeningBalanceSuspense);

        return Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            // Only accounts that carry forward. AccountType knows which those
            // are; asking it here keeps one definition of "permanent".
            ->whereIn('type', $this->permanentTypes())
            // The suspense account is where the difference lands. Offering it
            // as somewhere to type a figure would let a reader balance the
            // sheet against the very account that reports it as unbalanced.
            ->when($suspense !== null, fn ($query) => $query->whereKeyNot($suspense->getKey()))
            ->orderBy('code')
            ->get();
    }

    /**
     * The opening entry for a year, draft or posted.
     */
    public function for(FiscalYear $year): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('kind', JournalEntryKind::Opening)
            // Matched on the date rather than on fiscal_year_id, because the
            // poster resolves the period only when an entry is posted — a draft
            // carries no fiscal year, and a draft is exactly what this has to
            // find in order to be revised.
            ->whereDate('entry_date', '>=', $year->start_date)
            ->whereDate('entry_date', '<=', $year->end_date)
            ->with('lines')
            ->first();
    }

    /**
     * Record what the company is bringing in, as a draft.
     *
     * Replaces any existing draft outright rather than merging into it: the
     * screen submits the complete picture every time, and merging would leave
     * an account the user had just cleared still carrying its old figure.
     *
     * @param  array<string, array{debit?: string|null, credit?: string|null}>  $balances  Keyed by account id.
     */
    public function record(FiscalYear $year, array $balances, ?string $userId = null): JournalEntry
    {
        $existing = $this->for($year);

        if ($existing !== null && $existing->isPosted()) {
            throw OpeningBalanceRejected::alreadyPosted($year);
        }

        $lines = $this->linesFrom($balances);

        if ($lines === []) {
            throw OpeningBalanceRejected::nothingToRecord($year);
        }

        return DB::transaction(function () use ($year, $lines, $userId, $existing): JournalEntry {
            $existing?->lines()->delete();
            $existing?->delete();

            return $this->poster->draft(
                date: $year->start_date,
                lines: $this->withSuspense($lines),
                description: __('accounting.opening_balances.description', ['year' => $year->name]),
                userId: $userId,
                kind: JournalEntryKind::Opening,
            );
        });
    }

    /**
     * Commit the draft to the ledger.
     */
    public function commit(FiscalYear $year, ?string $userId = null): JournalEntry
    {
        $entry = $this->for($year);

        if ($entry === null) {
            throw OpeningBalanceRejected::nothingToRecord($year);
        }

        if ($entry->isPosted()) {
            throw OpeningBalanceRejected::alreadyPosted($year);
        }

        return $this->poster->postDraft($entry, $userId);
    }

    /**
     * How far the entered balances are from balancing.
     *
     * Positive when debits exceed credits. Reported so the screen can show the
     * figure before it becomes a suspense balance on the company's own books.
     *
     * @param  array<string, array{debit?: string|null, credit?: string|null}>  $balances
     */
    public function difference(array $balances): string
    {
        $debit = $this->zero();
        $credit = $this->zero();

        foreach ($balances as $amounts) {
            $debit = bcadd($debit, $this->amount($amounts['debit'] ?? null), self::SCALE);
            $credit = bcadd($credit, $this->amount($amounts['credit'] ?? null), self::SCALE);
        }

        return bcsub($debit, $credit, self::SCALE);
    }

    /**
     * Balance the entry against the suspense account.
     *
     * @param  list<JournalLineData>  $lines
     * @return list<JournalLineData>
     */
    private function withSuspense(array $lines): array
    {
        $difference = $this->zero();

        foreach ($lines as $line) {
            $difference = bcadd(
                $difference,
                bcsub($line->debit, $line->credit, self::SCALE),
                self::SCALE,
            );
        }

        if (bccomp($difference, '0', self::SCALE) === 0) {
            return $lines;
        }

        $suspense = $this->registry->get(SystemAccount::OpeningBalanceSuspense);

        // Excess debits are balanced by a credit to suspense, and the reverse.
        // The account is equity, so the figure lands where an unexplained
        // opening difference actually belongs — beside the owners' capital,
        // visible, rather than buried in it.
        $lines[] = bccomp($difference, '0', self::SCALE) > 0
            ? JournalLineData::credit($suspense->getKey(), $difference)
            : JournalLineData::debit($suspense->getKey(), bcmul($difference, '-1', self::SCALE));

        return $lines;
    }

    /**
     * @param  array<string, array{debit?: string|null, credit?: string|null}>  $balances
     * @return list<JournalLineData>
     */
    private function linesFrom(array $balances): array
    {
        $eligible = $this->eligibleAccounts()->keyBy(fn (Account $a): string => $a->getKey());

        $lines = [];

        foreach ($balances as $accountId => $amounts) {
            $debit = $this->amount($amounts['debit'] ?? null);
            $credit = $this->amount($amounts['credit'] ?? null);

            // An account left blank is not an instruction to post nothing to
            // it; it is an instruction to leave it out of the entry entirely.
            if (bccomp($debit, '0', self::SCALE) === 0 && bccomp($credit, '0', self::SCALE) === 0) {
                continue;
            }

            $account = $eligible->get($accountId);

            if ($account === null) {
                throw OpeningBalanceRejected::ineligibleAccount($accountId);
            }

            // Both sides on one account is a data-entry slip, not a balance.
            // Netting it silently would hide which of the two was wrong.
            if (bccomp($debit, '0', self::SCALE) !== 0 && bccomp($credit, '0', self::SCALE) !== 0) {
                throw OpeningBalanceRejected::twoSidedBalance($account);
            }

            $lines[] = bccomp($debit, '0', self::SCALE) !== 0
                ? JournalLineData::debit($accountId, $debit)
                : JournalLineData::credit($accountId, $credit);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function permanentTypes(): array
    {
        return array_values(array_map(
            static fn (AccountType $type): string => $type->value,
            array_filter(
                AccountType::cases(),
                static fn (AccountType $type): bool => $type->isPermanent(),
            ),
        ));
    }

    private function amount(?string $value): string
    {
        return blank($value) ? $this->zero() : bcadd($value, '0', self::SCALE);
    }

    private function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }
}
