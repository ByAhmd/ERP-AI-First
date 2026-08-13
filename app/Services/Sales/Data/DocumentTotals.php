<?php

declare(strict_types=1);

namespace App\Services\Sales\Data;

/**
 * What a document's lines add up to.
 *
 * These are the figures the customer is billed, the ledger is posted with and
 * the return is filed from — which is why they are computed once, here, and
 * every consumer reads the same three numbers.
 */
final readonly class DocumentTotals
{
    /**
     * @param  array<string, array{category: ?string, rate: string, net: string, tax: string}>  $taxBreakdown
     *                                                                                                         One entry per (category, rate) group, keyed by group.
     */
    public function __construct(
        public string $subtotalNet,
        public string $discountTotal,
        public string $taxTotal,
        public string $total,
        public array $taxBreakdown,
    ) {}
}
