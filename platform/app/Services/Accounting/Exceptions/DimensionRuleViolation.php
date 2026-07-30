<?php

declare(strict_types=1);

namespace App\Services\Accounting\Exceptions;

use App\Enums\DimensionScope;
use App\Models\Dimension;
use App\Models\DimensionValue;
use RuntimeException;

final class DimensionRuleViolation extends RuntimeException
{
    public static function generalLimitReached(): self
    {
        return new self(__('accounting.errors.dimension_general_limit', [
            'limit' => DimensionScope::GENERAL_LIMIT,
        ]));
    }

    public static function scopeChangeWhileInUse(Dimension $dimension): self
    {
        return new self(__('accounting.errors.dimension_scope_locked', [
            'dimension' => $dimension->displayName(),
        ]));
    }

    public static function inUse(Dimension $dimension): self
    {
        return new self(__('accounting.errors.dimension_in_use', [
            'dimension' => $dimension->displayName(),
        ]));
    }

    /**
     * A value belonging to a different dimension than the one it is filed under.
     */
    public static function valueDimensionMismatch(DimensionValue $value, Dimension $dimension): self
    {
        return new self(__('accounting.errors.dimension_value_mismatch', [
            'value' => $value->displayName(),
            'dimension' => $dimension->displayName(),
        ]));
    }

    public static function requiredDimensionMissing(Dimension $dimension, int $lineNumber): self
    {
        return new self(__('accounting.errors.dimension_required', [
            'dimension' => $dimension->displayName(),
            'line' => $lineNumber,
        ]));
    }

    public static function inactiveValue(DimensionValue $value): self
    {
        return new self(__('accounting.errors.dimension_value_inactive', [
            'value' => $value->displayName(),
        ]));
    }
}
