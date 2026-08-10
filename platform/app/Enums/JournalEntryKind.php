<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a journal entry is for.
 *
 * Almost every entry records something that happened — a sale, a payment, a
 * depreciation charge. A few record the ledger's own boundaries instead: the
 * balances a company arrives with when it migrates onto the platform, and, if
 * the platform ever posts them, the transfers that close a year.
 *
 * Held as a classification rather than inferred from the entry's date or
 * description, because both of those are the user's to change and the
 * distinction decides whether an entry may be edited, duplicated or replaced.
 */
enum JournalEntryKind: string implements HasLabel
{
    case Standard = 'standard';
    case Opening = 'opening';

    public function getLabel(): string
    {
        return __("accounting.entry_kind.{$this->value}");
    }

    /**
     * Whether a company may hold more than one of these per fiscal year.
     *
     * Opening balances are the state a year begins in, so a second one is not
     * a second fact — it is a contradiction of the first.
     */
    public function isUniquePerFiscalYear(): bool
    {
        return $this === self::Opening;
    }
}
