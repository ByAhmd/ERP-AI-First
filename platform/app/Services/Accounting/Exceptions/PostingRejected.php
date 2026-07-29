<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Models\Account;
use App\Models\JournalEntry;
use RuntimeException;

/**
 * A journal entry that cannot be accepted into the ledger.
 *
 * Each condition below would, if allowed through, leave the ledger in a state
 * that no report could reconcile.
 */
final class PostingRejected extends RuntimeException
{
    public static function unbalanced(string $debits, string $credits): self
    {
        return new self(__('accounting.errors.unbalanced', [
            'debits' => $debits,
            'credits' => $credits,
            'difference' => bcsub($debits, $credits, 4),
        ]));
    }

    public static function tooFewLines(): self
    {
        return new self(__('accounting.errors.too_few_lines'));
    }

    /**
     * A line carrying both a debit and a credit is ambiguous: its net effect is
     * representable as one or the other, and leaving both invites the two from
     * disagreeing after an edit.
     */
    public static function lineHasBothSides(int $lineNumber): self
    {
        return new self(__('accounting.errors.line_has_both_sides', ['line' => $lineNumber]));
    }

    public static function lineHasNoAmount(int $lineNumber): self
    {
        return new self(__('accounting.errors.line_has_no_amount', ['line' => $lineNumber]));
    }

    public static function negativeAmount(int $lineNumber): self
    {
        return new self(__('accounting.errors.negative_amount', ['line' => $lineNumber]));
    }

    public static function accountNotPostable(Account $account): self
    {
        return new self(__('accounting.errors.account_not_postable', [
            'account' => $account->displayName(),
        ]));
    }

    public static function accountNotFound(string $accountId): self
    {
        return new self(__('accounting.errors.account_not_found', ['id' => $accountId]));
    }

    public static function noOpenPeriod(\DateTimeInterface $date): self
    {
        return new self(__('accounting.errors.no_open_period', [
            'date' => $date->format('Y-m-d'),
        ]));
    }

    public static function periodClosed(\DateTimeInterface $date): self
    {
        return new self(__('accounting.errors.period_closed', [
            'date' => $date->format('Y-m-d'),
        ]));
    }

    public static function alreadyPosted(JournalEntry $entry): self
    {
        return new self(__('accounting.errors.already_posted', ['number' => $entry->number]));
    }

    public static function alreadyReversed(JournalEntry $entry): self
    {
        return new self(__('accounting.errors.already_reversed', ['number' => $entry->number]));
    }

    public static function cannotReverseDraft(JournalEntry $entry): self
    {
        return new self(__('accounting.errors.cannot_reverse_draft'));
    }
}
