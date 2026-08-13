<?php

declare(strict_types=1);

namespace App\Services\Sales\Exceptions;

use App\Models\Tax;
use RuntimeException;

/**
 * A tax rate that cannot be accepted.
 *
 * Both conditions below would, if allowed through, produce documents that
 * cannot be filed: a zero-rated supply carrying tax, or a company with no way
 * to describe a supply ZATCA still expects to see reported.
 */
final class TaxRuleViolation extends RuntimeException
{
    /**
     * Zero-rated and exempt are defined by carrying no tax. A rate against
     * either is a mistake, and one that would be charged to a customer.
     */
    public static function rateOnZeroCategory(Tax $tax): self
    {
        return new self(__('sales.taxes.errors.rate_on_zero_category', [
            'tax' => $tax->name,
        ]));
    }

    /**
     * The seeded rates are resolved by category when a document is posted.
     * Deleting one leaves a company unable to invoice that kind of supply at
     * all, which deactivating does not.
     */
    public static function systemTaxDeletion(Tax $tax): self
    {
        return new self(__('sales.taxes.errors.system_delete', [
            'tax' => $tax->name,
        ]));
    }
}
