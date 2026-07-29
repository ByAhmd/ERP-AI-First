<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use App\Services\Accounting\Exceptions\LedgerImmutable;

/**
 * Enforces immutability of the posted ledger.
 *
 * A posted entry is a financial record. It may not be edited and may not be
 * deleted; the only correction is a reversing entry, which leaves both the
 * original and the correction visible. An audit trail that can be quietly
 * rewritten is not an audit trail, and a deleted posting takes its own evidence
 * with it.
 *
 * Enforced here rather than in the service so it holds regardless of which code
 * path reaches the model — including future imports, jobs and console commands.
 */
final class JournalEntryObserver
{
    /**
     * Fields the posting service itself sets during the draft-to-posted
     * transition. Everything else on a posted entry is frozen.
     *
     * @var list<string>
     */
    private const POSTING_FIELDS = [
        'status',
        'posted_at',
        'posted_by_id',
        'number',
        'total_debit',
        'total_credit',
        'fiscal_year_id',
        'accounting_period_id',
    ];

    public function updating(JournalEntry $entry): void
    {
        // Drafts are working material and may be changed freely; only what was
        // already posted is frozen. Read raw, because getOriginal() applies the
        // enum cast and would never equal this string — which silently froze
        // drafts as well.
        if ($entry->getRawOriginal('status') === JournalEntryStatus::Draft->value) {
            return;
        }

        $changed = array_keys($entry->getDirty());
        $forbidden = array_diff($changed, self::POSTING_FIELDS);

        if ($forbidden !== []) {
            throw LedgerImmutable::cannotModify($entry, $forbidden);
        }
    }

    public function deleting(JournalEntry $entry): void
    {
        if ($entry->isPosted()) {
            throw LedgerImmutable::cannotDelete($entry);
        }
    }
}
