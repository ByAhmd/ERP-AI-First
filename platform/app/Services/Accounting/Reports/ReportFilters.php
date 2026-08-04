<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Illuminate\Database\Query\Builder;

/**
 * The narrowing a reader applied to a financial report.
 *
 * Every ledger report offers the same two, and each was previously rebuilt by
 * hand wherever it was needed — the dimension filter in particular is an
 * existence check against a second table, and a report that wrote it slightly
 * differently would quietly disagree with its neighbours about which lines
 * belong. Holding both here means there is one definition to be right about.
 *
 * Narrowing is applied to journal *lines*, not entries. That distinction
 * matters on the balance sheet: an entry whose lines span two branches
 * contributes only part of itself to either, so a filtered statement is not
 * required to balance. Reports state that rather than hide it.
 */
final readonly class ReportFilters
{
    public function __construct(
        public ?string $branchId = null,
        public ?string $dimensionValueId = null,
    ) {}

    /**
     * Build from a Filament form's state.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $value = static function (string $key) use ($state): ?string {
            $raw = $state[$key] ?? null;

            return filled($raw) && is_scalar($raw) ? (string) $raw : null;
        };

        return new self(
            branchId: $value('branch_id'),
            dimensionValueId: $value('dimension_value_id'),
        );
    }

    public static function none(): self
    {
        return new self;
    }

    /**
     * Whether anything is actually narrowed.
     *
     * Read by the balance sheet before it asserts that assets equal liabilities
     * plus equity: unfiltered that identity is guaranteed, filtered it is not.
     */
    public function narrowsLines(): bool
    {
        return $this->branchId !== null || $this->dimensionValueId !== null;
    }

    /**
     * Constrain a query whose journal lines carry the given alias.
     *
     * @param  Builder  $query  Selecting from `journal_entry_lines`.
     */
    public function applyTo(Builder $query, string $lineAlias = 'l'): Builder
    {
        if ($this->branchId !== null) {
            $query->where("{$lineAlias}.branch_id", $this->branchId);
        }

        if ($this->dimensionValueId !== null) {
            // Dimension tags live in their own table, so this is an existence
            // check rather than a column comparison. A join would multiply
            // lines carrying more than one tag and double their amounts.
            $query->whereExists(function (Builder $sub) use ($lineAlias): void {
                $sub->selectRaw('1')
                    ->from('journal_entry_line_dimensions as d')
                    ->whereColumn('d.journal_entry_line_id', "{$lineAlias}.id")
                    ->where('d.dimension_value_id', $this->dimensionValueId);
            });
        }

        return $query;
    }
}
