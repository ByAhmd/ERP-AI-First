<?php

declare(strict_types=1);

namespace App\Services\Assets\Exceptions;

use RuntimeException;

/**
 * A depreciation run that cannot proceed.
 */
final class RunRejected extends RuntimeException
{
    public static function nothingToPost(): self
    {
        return new self(__('assets.errors.nothing_to_post'));
    }

    public static function missingPeriod(string $date): self
    {
        return new self(__('assets.errors.missing_period', ['date' => $date]));
    }

    public static function notApproved(string $reference): self
    {
        return new self(__('assets.errors.run_not_approved', ['reference' => $reference]));
    }

    public static function boundToDisposal(string $reference): self
    {
        return new self(__('assets.errors.run_bound_to_disposal', ['reference' => $reference]));
    }

    public static function hasDisposedAssets(string $reference): self
    {
        return new self(__('assets.errors.run_has_disposed_assets', ['reference' => $reference]));
    }
}
