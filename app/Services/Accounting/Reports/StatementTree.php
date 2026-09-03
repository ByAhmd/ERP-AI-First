<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\NormalBalance;
use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * Turns account balances into the nested rows of a statement section.
 *
 * Three rules, and all three are things an accountant would notice immediately
 * if they were wrong:
 *
 *  - A heading's figure is the sum of everything beneath it, however deep. Only
 *    leaves carry postings, so every total on the statement is computed here.
 *  - Each figure reads in its account's natural direction, so a payable of
 *    5,000 shows as 5,000 rather than as minus 5,000. Accounts that genuinely
 *    sit against their type — accumulated depreciation, sales returns — come
 *    out negative, which is exactly how they belong on the statement.
 *  - A section named after a single root account lists that root's children
 *    instead of the root, because a heading immediately followed by a row of
 *    the same name and the same figure is noise.
 */
final class StatementTree
{
    private const SCALE = 4;

    /**
     * Build one section's rows and totals.
     *
     * @param  Collection<int, Account>  $accounts  Every account in the section, roots included.
     * @param  list<array<string, array{debit: string, credit: string}>>  $balances  One reading per column.
     * @param  int  $depth  How many levels below the section heading to show.
     * @return array{lines: list<StatementLine>, totals: list<string>}
     */
    public function build(
        Collection $accounts,
        array $balances,
        int $depth,
        bool $includeEmpty = false,
        DrillKind $drillKind = DrillKind::PeriodMovements,
    ): array {
        $columns = count($balances);

        if ($accounts->isEmpty()) {
            return ['lines' => [], 'totals' => $this->zeros($columns)];
        }

        /** @var array<string, list<Account>> $childrenOf */
        $childrenOf = [];
        $present = [];

        foreach ($accounts as $account) {
            $present[$account->getKey()] = true;
        }

        foreach ($accounts as $account) {
            // An account whose parent is outside the section — the cost of
            // sales subtree lifted out of expenses, say — is a root here even
            // though it is not one in the chart.
            $parent = $account->parent_id;
            $key = $parent !== null && isset($present[$parent]) ? $parent : '';

            $childrenOf[$key][] = $account;
        }

        $roots = $childrenOf[''] ?? [];

        // A section named after one account lists what is inside it, not it —
        // a heading immediately followed by a row of the same name and the same
        // figure tells the reader nothing. Unless there is nothing inside, in
        // which case the account itself is the only detail there is, and
        // dropping it would leave a total with no line above it.
        $onlyRoot = count($roots) === 1 ? $roots[0] : null;

        $topLevel = $onlyRoot !== null && ($childrenOf[$onlyRoot->getKey()] ?? []) !== []
            ? $childrenOf[$onlyRoot->getKey()]
            : $roots;

        // The total is always the roots' own subtotals, whether or not the
        // roots are the rows being shown. Unwrapping changes the presentation,
        // never the arithmetic.
        $totals = $this->zeros($columns);
        $lines = [];

        foreach ($roots as $root) {
            $totals = $this->add($totals, $this->subtotal($root, $childrenOf, $balances));
        }

        foreach ($topLevel as $account) {
            $line = $this->line($account, $childrenOf, $balances, $depth, 0, $includeEmpty, $drillKind);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return ['lines' => $lines, 'totals' => $totals];
    }

    /**
     * One row and everything under it.
     *
     * @param  array<string, list<Account>>  $childrenOf
     * @param  list<array<string, array{debit: string, credit: string}>>  $balances
     */
    private function line(
        Account $account,
        array $childrenOf,
        array $balances,
        int $maxDepth,
        int $level,
        bool $includeEmpty,
        DrillKind $drillKind,
    ): ?StatementLine {
        $amounts = $this->subtotal($account, $childrenOf, $balances);

        $children = [];

        // Below the requested level the detail is folded into this row: the
        // figures still count, they simply stop being itemised.
        if ($level + 1 < $maxDepth) {
            foreach ($childrenOf[$account->getKey()] ?? [] as $child) {
                $line = $this->line($child, $childrenOf, $balances, $maxDepth, $level + 1, $includeEmpty, $drillKind);

                if ($line !== null) {
                    $children[] = $line;
                }
            }
        }

        $line = new StatementLine(
            name: $this->name($account),
            amounts: $amounts,
            depth: $level,
            accountId: $account->getKey(),
            code: $account->code,
            children: $children,
            drill: $this->drillFor($account, $childrenOf, $drillKind),
        );

        // A chart of accounts is provisioned complete and a new company uses a
        // fraction of it. Left in, the first statement anyone opens is fifty
        // rows of nothing.
        return $includeEmpty || $line->hasAmount() ? $line : null;
    }

    /**
     * An account's own balance plus every descendant's.
     *
     * @param  array<string, list<Account>>  $childrenOf
     * @param  list<array<string, array{debit: string, credit: string}>>  $balances
     * @return list<string>
     */
    private function subtotal(Account $account, array $childrenOf, array $balances): array
    {
        $totals = $this->own($account, $balances);

        foreach ($childrenOf[$account->getKey()] ?? [] as $child) {
            $totals = $this->add($totals, $this->subtotal($child, $childrenOf, $balances));
        }

        return $totals;
    }

    /**
     * What is posted to this account alone, in its natural direction.
     *
     * @param  list<array<string, array{debit: string, credit: string}>>  $balances
     * @return list<string>
     */
    private function own(Account $account, array $balances): array
    {
        $id = $account->getKey();
        $debitNormal = $account->type->normalBalance() === NormalBalance::Debit;

        $amounts = [];

        foreach ($balances as $column) {
            $debit = $column[$id]['debit'] ?? '0';
            $credit = $column[$id]['credit'] ?? '0';

            $amounts[] = $debitNormal
                ? bcsub($debit, $credit, self::SCALE)
                : bcsub($credit, $debit, self::SCALE);
        }

        return $amounts;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private function add(array $a, array $b): array
    {
        $sum = [];

        foreach ($a as $index => $value) {
            $sum[] = bcadd($value, $b[$index] ?? '0', self::SCALE);
        }

        return $sum;
    }

    /**
     * @return list<string>
     */
    private function zeros(int $columns): array
    {
        return array_fill(0, $columns, bcadd('0', '0', self::SCALE));
    }

    private function name(Account $account): string
    {
        return app()->getLocale() === 'en' && filled($account->name_en)
            ? $account->name_en
            : $account->name;
    }

    /**
     * @param  array<string, list<Account>>  $childrenOf
     */
    private function drillFor(Account $account, array $childrenOf, DrillKind $kind): StatementDrillTarget
    {
        if (($childrenOf[$account->getKey()] ?? []) !== []) {
            return StatementDrillTarget::subtree($kind, $account->getKey());
        }

        return StatementDrillTarget::account($kind, $account->getKey());
    }
}
