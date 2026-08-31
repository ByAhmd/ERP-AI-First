<?php

declare(strict_types=1);

namespace App\Services\Assets\Exceptions;

use App\Models\FixedAssetDisposal;
use RuntimeException;

/**
 * A disposal that cannot be approved.
 */
final class DisposalRejected extends RuntimeException
{
    public static function alreadyApproved(FixedAssetDisposal $disposal): self
    {
        return new self(__('assets.errors.disposal_already_approved', [
            'reference' => $disposal->reference,
        ]));
    }

    public static function notDraft(): self
    {
        return new self(__('assets.errors.disposal_not_draft'));
    }

    public static function assetNotActive(string $name): self
    {
        return new self(__('assets.errors.asset_not_active', ['name' => $name]));
    }

    public static function proceedsRequired(): self
    {
        return new self(__('assets.errors.proceeds_required'));
    }

    public static function proceedsAccountRequired(): self
    {
        return new self(__('assets.errors.proceeds_account_required'));
    }
}
