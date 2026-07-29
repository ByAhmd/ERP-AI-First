<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a journal entry.
 *
 * There are only two meaningful states, and the boundary between them is the
 * most important rule in the ledger: a draft is working material and may be
 * changed freely; a posted entry is a financial record and is immutable.
 * Correcting a posted entry means reversing it, never editing it.
 */
enum JournalEntryStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function getLabel(): string
    {
        return __("accounting.journal_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Posted => 'success',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the entry contributes to account balances and reports.
     *
     * Drafts are excluded from every ledger figure. A trial balance that
     * included them would not be a trial balance.
     */
    public function affectsLedger(): bool
    {
        return $this === self::Posted;
    }
}
