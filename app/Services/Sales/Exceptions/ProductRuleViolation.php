<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * A product change the catalogue cannot accept.
 */
final class ProductRuleViolation extends RuntimeException
{
    /**
     * The tracking flag is immutable once stock has moved: flipping it either
     * orphans a carried balance or restarts the average mid-life, and both
     * part the books from the shelf silently.
     */
    public static function trackingFlagFrozen(Product $product): self
    {
        return new self(__('inventory.errors.tracking_flag_frozen', [
            'product' => $product->name,
        ]));
    }
}
