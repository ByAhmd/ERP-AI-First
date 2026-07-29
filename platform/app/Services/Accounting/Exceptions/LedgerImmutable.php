<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Models\JournalEntry;
use RuntimeException;

/**
 * An attempt to alter the posted ledger.
 *
 * Posted entries are financial records. Correction is by reversal, which leaves
 * both the original and the correction visible — the property that makes the
 * ledger auditable at all.
 */
final class LedgerImmutable extends RuntimeException
{
    /**
     * @param  list<string>  $fields
     */
    public static function cannotModify(JournalEntry $entry, array $fields): self
    {
        return new self(__('accounting.errors.entry_immutable', [
            'number' => $entry->number,
            'fields' => implode(', ', $fields),
        ]));
    }

    public static function cannotDelete(JournalEntry $entry): self
    {
        return new self(__('accounting.errors.entry_undeletable', [
            'number' => $entry->number,
        ]));
    }
}
