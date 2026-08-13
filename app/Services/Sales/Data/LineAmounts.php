<?php

declare(strict_types=1);

namespace App\Services\Sales\Data;

use App\Enums\TaxCategory;

/**
 * What one invoice line resolves to.
 *
 * Every figure is a scaled decimal string, as everywhere the ledger is
 * involved. The line keeps its own rate and category rather than pointing at a
 * tax record for them: a rate that is edited later must not restate an invoice
 * already issued, and zero-rated and exempt are both 0% but are reported
 * differently.
 */
final readonly class LineAmounts
{
    public function __construct(
        public string $netAmount,
        public string $taxAmount,
        public string $lineTotal,
        public string $discountAmount,
        public string $taxRate,
        public ?TaxCategory $taxCategory,
        public bool $isInclusive,
    ) {}

    /**
     * The key a document groups this line under when it totals its tax.
     *
     * Category and rate together, because a supply at 0% zero-rated and one at
     * 0% exempt belong in different boxes of the same return.
     */
    public function taxGroup(): string
    {
        return ($this->taxCategory instanceof TaxCategory ? $this->taxCategory->value : '-')
            .'@'.$this->taxRate;
    }
}
