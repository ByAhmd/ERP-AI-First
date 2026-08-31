<?php

declare(strict_types=1);

namespace App\Services\Inventory\Exceptions;

use App\Models\StockAdjustment;
use RuntimeException;

/**
 * A stock adjustment that cannot be accepted.
 */
final class AdjustmentRejected extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('inventory.adjustments.errors.no_items'));
    }

    public static function alreadyApproved(StockAdjustment $adjustment): self
    {
        return new self(__('inventory.adjustments.errors.already_approved', [
            'reference' => $adjustment->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('inventory.adjustments.errors.not_draft'));
    }

    public static function zeroLine(int $lineNumber): self
    {
        return new self(__('inventory.adjustments.errors.zero_line', [
            'line' => $lineNumber,
        ]));
    }

    public static function openingNegative(): self
    {
        return new self(__('inventory.adjustments.errors.opening_negative'));
    }

    public static function costRequired(int $lineNumber): self
    {
        return new self(__('inventory.adjustments.errors.cost_required_line', [
            'line' => $lineNumber,
        ]));
    }
}
