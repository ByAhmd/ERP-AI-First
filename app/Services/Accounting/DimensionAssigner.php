<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Dimension;
use App\Models\DimensionValue;
use App\Models\JournalEntryLine;
use App\Services\Accounting\Exceptions\DimensionRuleViolation;

/**
 * Validates and records the dimension tags on a ledger line.
 *
 * Kept out of {@see JournalPoster} because it is a self-contained concern with
 * its own rules, and because it is resolved once per entry rather than once per
 * line — a company's dimensions and values are loaded a single time and reused
 * across every line of the entry.
 */
final class DimensionAssigner
{
    /**
     * @var array<string, Dimension>|null
     */
    private ?array $dimensions = null;

    /**
     * Record the assignments for one line.
     *
     * @param  array<string, string>  $assignments  dimension id => value id
     */
    public function assign(JournalEntryLine $line, array $assignments, int $lineNumber): void
    {
        $dimensions = $this->dimensions();
        $values = $this->resolveValues($assignments);

        foreach ($assignments as $dimensionId => $valueId) {
            if (blank($valueId)) {
                continue;
            }

            $dimension = $dimensions[$dimensionId] ?? null;

            if ($dimension === null || ! $dimension->is_active) {
                // An unknown or retired dimension is silently dropped rather
                // than failing the posting: the tag carries no accounting
                // meaning, and refusing the entry would block a correction.
                continue;
            }

            $value = $values[$valueId] ?? null;

            if ($value === null) {
                continue;
            }

            // A value filed under a different dimension would make reports
            // attribute the amount to something the user never chose.
            if ($value->dimension_id !== $dimension->getKey()) {
                throw DimensionRuleViolation::valueDimensionMismatch($value, $dimension);
            }

            if (! $value->is_active) {
                throw DimensionRuleViolation::inactiveValue($value);
            }

            $line->dimensionAssignments()->create([
                'company_id' => $line->company_id,
                'dimension_id' => $dimension->getKey(),
                'dimension_value_id' => $value->getKey(),
            ]);
        }

        $this->assertRequiredPresent($assignments, $lineNumber);
    }

    /**
     * Dimensions that must be supplied on every line.
     *
     * @return array<string, Dimension>
     */
    public function requiredDimensions(): array
    {
        return array_filter(
            $this->dimensions(),
            static fn (Dimension $d): bool => $d->is_required && $d->is_active,
        );
    }

    /**
     * @param  array<string, string>  $assignments
     */
    private function assertRequiredPresent(array $assignments, int $lineNumber): void
    {
        foreach ($this->requiredDimensions() as $dimension) {
            if (blank($assignments[$dimension->getKey()] ?? null)) {
                throw DimensionRuleViolation::requiredDimensionMissing($dimension, $lineNumber);
            }
        }
    }

    /**
     * @return array<string, Dimension>
     */
    private function dimensions(): array
    {
        return $this->dimensions ??= Dimension::query()->get()->keyBy('id')->all();
    }

    /**
     * Loaded in one query and keyed by id, so tagging a twenty-line entry does
     * not mean twenty round trips per dimension.
     *
     * @param  array<string, string>  $assignments
     * @return array<string, DimensionValue>
     */
    private function resolveValues(array $assignments): array
    {
        $ids = array_values(array_filter($assignments));

        if ($ids === []) {
            return [];
        }

        return DimensionValue::query()->whereKey($ids)->get()->keyBy('id')->all();
    }

    /**
     * Discard the cached dimensions. Needed when the company context changes
     * mid-process, as it does in a queue worker handling several companies.
     */
    public function flush(): void
    {
        $this->dimensions = null;
    }
}
