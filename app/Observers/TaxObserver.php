<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tax;
use App\Services\Sales\Exceptions\TaxRuleViolation;

/**
 * Keeps the tax table in a state a VAT return can be built from.
 *
 * Three rules, all of which protect a filing rather than a screen:
 *
 *  - A zero-rated or exempt tax carries no rate.
 *  - The seeded rates cannot be deleted, only deactivated.
 *  - One default at a time, so a line that names no tax resolves to exactly
 *    one thing.
 */
final class TaxObserver
{
    public function saving(Tax $tax): void
    {
        // Categories other than standard are defined by charging nothing. A
        // rate here would be charged to a customer and reported to ZATCA.
        if (! $tax->category->allowsRate() && ! $tax->isZero()) {
            throw TaxRuleViolation::rateOnZeroCategory($tax);
        }
    }

    public function saved(Tax $tax): void
    {
        $this->demoteOtherDefaults($tax);
    }

    public function deleting(Tax $tax): void
    {
        if ($tax->is_system) {
            throw TaxRuleViolation::systemTaxDeletion($tax);
        }
    }

    /**
     * Exactly one default.
     *
     * Written after the save rather than before it so the rate being promoted
     * is already persisted — demoting first would briefly leave a company with
     * no default at all, which a concurrent document would resolve to nothing.
     */
    private function demoteOtherDefaults(Tax $tax): void
    {
        if (! $tax->is_default) {
            return;
        }

        Tax::query()
            ->whereKeyNot($tax->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
