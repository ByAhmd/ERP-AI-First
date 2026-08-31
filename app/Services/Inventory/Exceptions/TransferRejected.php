<?php

declare(strict_types=1);

namespace App\Services\Inventory\Exceptions;

use App\Models\InventoryTransfer;
use RuntimeException;

/**
 * An inventory transfer that cannot be accepted.
 */
final class TransferRejected extends RuntimeException
{
    public static function noItems(): self
    {
        return new self(__('inventory.transfers.errors.no_items'));
    }

    public static function alreadyApproved(InventoryTransfer $transfer): self
    {
        return new self(__('inventory.transfers.errors.already_approved', [
            'reference' => $transfer->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('inventory.transfers.errors.not_draft'));
    }

    public static function sameBranch(): self
    {
        return new self(__('inventory.transfers.errors.same_branch'));
    }

    public static function zeroLine(int $lineNumber): self
    {
        return new self(__('inventory.transfers.errors.zero_line', [
            'line' => $lineNumber,
        ]));
    }
}
