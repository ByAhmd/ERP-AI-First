<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\DimensionScope;
use App\Models\Dimension;
use App\Services\Accounting\Exceptions\DimensionRuleViolation;

/**
 * Enforces the rules that keep dimensions meaningful.
 *
 * Two of them, both structural:
 *
 *  - At most two general dimensions per company, matching Qoyod. Every document
 *    carries every general dimension, so an unbounded number would mean an
 *    unbounded number of tags on each line.
 *  - A dimension already used in the ledger cannot change scope. Moving one from
 *    specific to general would retroactively imply it was recorded on documents
 *    that never carried it, and every report sliced by it would be wrong for
 *    prior periods.
 */
final class DimensionObserver
{
    public function saving(Dimension $dimension): void
    {
        $this->guardScopeChange($dimension);
        $this->guardGeneralLimit($dimension);
    }

    public function deleting(Dimension $dimension): void
    {
        if ($dimension->hasLedgerUsage()) {
            throw DimensionRuleViolation::inUse($dimension);
        }
    }

    private function guardScopeChange(Dimension $dimension): void
    {
        if (! $dimension->exists || ! $dimension->isDirty('scope')) {
            return;
        }

        if ($dimension->hasLedgerUsage()) {
            throw DimensionRuleViolation::scopeChangeWhileInUse($dimension);
        }
    }

    private function guardGeneralLimit(Dimension $dimension): void
    {
        $becomingGeneral = $dimension->scope === DimensionScope::General
            && (! $dimension->exists || $dimension->isDirty('scope'));

        if (! $becomingGeneral) {
            return;
        }

        $existing = Dimension::query()
            ->where('scope', DimensionScope::General->value)
            ->when($dimension->exists, fn ($query) => $query->whereKeyNot($dimension->getKey()))
            ->count();

        if ($existing >= DimensionScope::GENERAL_LIMIT) {
            throw DimensionRuleViolation::generalLimitReached();
        }
    }
}
