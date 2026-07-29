<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a fiscal year or accounting period.
 *
 * The status governs whether entries may be posted into the date range. It is
 * the mechanism that stops a closed month being altered after its figures have
 * been reported or filed.
 */
enum PeriodStatus: string implements HasColor, HasLabel
{
    case Open = 'open';

    /**
     * Closed to routine posting, but still open to adjusting entries.
     *
     * The state a period sits in between month-end and the auditor signing off:
     * day-to-day entry stops, corrections are still possible.
     */
    case Adjusting = 'adjusting';

    case Closed = 'closed';

    /**
     * Permanently sealed. Only a fiscal year reaches this, at year-end close.
     */
    case Locked = 'locked';

    public function getLabel(): string
    {
        return __("accounting.period_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Adjusting => 'warning',
            self::Closed => 'gray',
            self::Locked => 'danger',
        };
    }

    /**
     * Whether new entries may be posted into this period.
     */
    public function acceptsPostings(): bool
    {
        return $this === self::Open || $this === self::Adjusting;
    }

    /**
     * Whether the period may be reopened.
     *
     * A locked year cannot: closing a year posts the income statement to
     * retained earnings, and reopening it would double-count that transfer.
     * Correction after locking is by adjusting entry in the following year.
     */
    public function canReopen(): bool
    {
        return $this === self::Closed || $this === self::Adjusting;
    }
}
