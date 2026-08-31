<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\SystemAccount;
use App\Models\Product;
use App\Services\Accounting\AccountRegistry;
use Illuminate\Support\Collection;

/**
 * What a stocked line snapshots — resolved in ONE place.
 *
 * The snapshot IS the redirect: a stocked purchase line's account is written
 * as المخزون at recalculation time, so the document says exactly what the
 * ledger will do and the posters stay account-agnostic. Two forms and four
 * recalculators consult this; none of them may re-derive it.
 */
final class StockedLineDefaults
{
    public function __construct(
        private readonly AccountRegistry $registry,
    ) {}

    /**
     * The tracked flag per product, read once for a document's lines.
     *
     * @param  list<?string>  $productIds
     * @return array<string, bool>
     */
    public function stockedMap(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter($productIds)));

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, Product> $products */
        $products = Product::query()->whereKey($ids)->get();

        $map = [];

        foreach ($products as $product) {
            $map[$product->getKey()] = $product->isStocked();
        }

        return $map;
    }

    public function inventoryAccountId(): string
    {
        return $this->registry->get(SystemAccount::Inventory)->getKey();
    }
}
