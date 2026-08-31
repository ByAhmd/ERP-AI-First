<?php

declare(strict_types=1);

namespace App\Services\Inventory\Data;

/**
 * One product's share of a stock mutation.
 *
 * Quantities are positive; the direction comes from which ledger method the
 * line is handed to. The value is meaningful only on receipts — the
 * currency-scale figure the ledger is being debited with — and ignored on
 * issues, where the relief is resolved under the lock instead.
 */
final readonly class StockLine
{
    public function __construct(
        public string $productId,
        public string $quantity,
        public string $value = '0',
    ) {}
}
