<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Enums\ComparisonInterval;

/**
 * How a reader asked for a financial statement to be presented.
 *
 * Both statements take the same five choices, and passing them as five
 * positional arguments to two services invites the day someone swaps the depth
 * and the comparison count — two integers, no complaint from the compiler, and
 * a statement that quietly reports the wrong thing.
 */
final readonly class StatementOptions
{
    /**
     * Levels shown beneath a section heading before detail is folded in.
     *
     * Three reaches the postable accounts of the supplied chart — group, then
     * sub-group, then account — which is the statement most readers want before
     * they start drilling.
     */
    public const int DEFAULT_DEPTH = 3;

    public const int MAX_DEPTH = 7;

    public function __construct(
        public ReportFilters $filters = new ReportFilters,
        public ComparisonInterval $interval = ComparisonInterval::None,
        public int $comparisons = 0,
        public int $depth = self::DEFAULT_DEPTH,
        public bool $includeEmpty = false,
    ) {}

    /**
     * Build from a Filament form's state.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $interval = ComparisonInterval::tryFrom((string) ($state['interval'] ?? ''))
            ?? ComparisonInterval::None;

        $depth = (int) ($state['depth'] ?? self::DEFAULT_DEPTH);

        return new self(
            filters: ReportFilters::fromArray($state),
            interval: $interval,
            comparisons: (int) ($state['comparisons'] ?? 0),
            depth: max(1, min($depth, self::MAX_DEPTH)),
            includeEmpty: (bool) ($state['include_empty'] ?? false),
        );
    }
}
