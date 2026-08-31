<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * The built debt aging report, in whichever view was asked for.
 *
 * Summary rows carry the five buckets per contact; detail rows carry one
 * document each with its signed delay. Only one of the two lists is filled,
 * matching the view mode — the other stays empty.
 */
final readonly class DebtAgingData
{
    public const BUCKETS = ['current', 'b1_30', 'b31_60', 'b61_90', 'over_90'];

    /**
     * @param  list<DebtAgingSummaryRow>  $summary
     * @param  list<DebtAgingDetailRow>  $details
     * @param  array<string, string>  $totals  bucket key => amount, plus 'total'
     */
    public function __construct(
        public array $summary,
        public array $details,
        public array $totals,
    ) {}
}
