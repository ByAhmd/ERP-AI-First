<?php

declare(strict_types=1);

namespace App\Services\Inventory\Data;

use App\Models\StockMovement;

/**
 * What a stock mutation did, keyed by product.
 *
 * The reliefs an issue resolved ARE the ledger figures the caller posts —
 * reading them from here rather than recomputing is what keeps the entry and
 * the subledger identical by construction.
 */
final readonly class StockResult
{
    /**
     * @param  array<string, StockMovement>  $movements
     */
    public function __construct(
        public array $movements,
    ) {}

    /**
     * The absolute value moved for one product.
     */
    public function valueFor(string $productId): string
    {
        $movement = $this->movements[$productId] ?? null;

        return $movement === null ? '0.0000' : ltrim((string) $movement->value, '-');
    }

    /**
     * Total absolute value across products.
     */
    public function totalValue(): string
    {
        $total = '0.0000';

        foreach ($this->movements as $movement) {
            $total = bcadd($total, ltrim((string) $movement->value, '-'), 4);
        }

        return $total;
    }

    /**
     * @return list<int>
     */
    public function movementIds(): array
    {
        return array_map(
            static fn (StockMovement $movement): int => (int) $movement->getKey(),
            array_values($this->movements),
        );
    }
}
