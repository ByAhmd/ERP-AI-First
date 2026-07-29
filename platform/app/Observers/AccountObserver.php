<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Account;
use App\Services\Accounting\Exceptions\AccountStructureViolation;

/**
 * Maintains chart-of-accounts invariants.
 *
 * Three of them, all of which protect prior-period figures from being restated
 * by a structural edit:
 *
 *  - A parent account stops accepting postings the moment it gains a child.
 *  - An account's type cannot change once it carries ledger history.
 *  - The materialised path and depth track the account's position in the tree.
 */
final class AccountObserver
{
    public function saving(Account $account): void
    {
        $this->guardTypeChange($account);
        $this->applyPath($account);
    }

    public function created(Account $account): void
    {
        $this->demoteParent($account);
    }

    public function updated(Account $account): void
    {
        if ($account->wasChanged('parent_id')) {
            $this->demoteParent($account);
            $this->repathDescendants($account);
        }
    }

    public function deleting(Account $account): void
    {
        if ($account->is_system) {
            throw AccountStructureViolation::systemAccountDeletion($account);
        }

        if ($account->hasLedgerHistory()) {
            throw AccountStructureViolation::accountHasHistory($account);
        }

        if ($account->children()->exists()) {
            throw AccountStructureViolation::accountHasChildren($account);
        }
    }

    /**
     * Reclassifying an account with history would move past amounts between
     * the balance sheet and the income statement, silently restating every
     * report that has already been produced.
     */
    private function guardTypeChange(Account $account): void
    {
        if (! $account->exists || ! $account->isDirty('type')) {
            return;
        }

        if ($account->hasLedgerHistory()) {
            throw AccountStructureViolation::typeChangeWithHistory($account);
        }
    }

    /**
     * A group account must not also hold postings of its own, or its total
     * disagrees with the sum of its children.
     */
    private function demoteParent(Account $account): void
    {
        if ($account->parent_id === null) {
            return;
        }

        $parent = $account->parent()->first();

        if ($parent === null || ! $parent->is_postable) {
            return;
        }

        if ($parent->hasLedgerHistory()) {
            throw AccountStructureViolation::parentHasHistory($parent);
        }

        $parent->forceFill(['is_postable' => false])->saveQuietly();
    }

    private function applyPath(Account $account): void
    {
        if ($account->parent_id === null) {
            $account->path = $account->code;
            $account->depth = 0;

            return;
        }

        $parent = $account->parent()->first();

        if ($parent === null) {
            return;
        }

        $account->path = $parent->path.'.'.$account->code;
        $account->depth = $parent->depth + 1;
    }

    /**
     * Re-path the subtree after a move.
     *
     * Saved individually rather than by a bulk string operation so each child
     * recomputes its own depth and the observer stays the single authority on
     * what a path looks like.
     */
    private function repathDescendants(Account $account): void
    {
        foreach ($account->children()->get() as $child) {
            $child->save();
            $this->repathDescendants($child);
        }
    }
}
