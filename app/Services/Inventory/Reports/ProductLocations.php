<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reports;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Collection;

/**
 * تقرير مواقع المنتجات — stocked products across the branches.
 *
 * One row per tracked product, one quantity column per branch, a total —
 * Qoyod's description verbatim: "جميع المنتجات المخزنة المتوفرة في كل
 * المواقع مع الكمية الإجمالية، مع إمكانية تحديد موقع معين". Zero-filled,
 * never blank; a product that has never moved still lists at zero so the
 * report answers "where is everything" completely.
 */
final class ProductLocations
{
    private const SCALE = 4;

    /**
     * @return array{
     *     branches: Collection<int, Branch>,
     *     rows: list<array{product_id: string, sku: ?string, name: string,
     *         quantities: array<string, string>, total: string}>,
     *     totals: array<string, string>,
     * }
     */
    public function build(?string $branchId = null): array
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->when($branchId !== null, fn ($q) => $q->whereKey($branchId))
            ->orderBy('code')
            ->get();

        $products = Product::query()
            ->where('track_inventory', true)
            ->orderBy('sku')
            ->get();

        $stocks = ProductStock::query()
            ->get(['product_id', 'branch_id', 'quantity_on_hand'])
            ->groupBy('product_id');

        $rows = [];
        $totals = [];

        foreach ($branches as $branch) {
            $totals[$branch->getKey()] = '0.0000';
        }

        $totals['total'] = '0.0000';

        foreach ($products as $product) {
            $productStocks = $stocks->get($product->getKey(), collect())
                ->keyBy('branch_id');

            $quantities = [];
            $rowTotal = '0.0000';

            foreach ($branches as $branch) {
                $qty = bcadd(
                    (string) ($productStocks[$branch->getKey()]->quantity_on_hand ?? '0'),
                    '0',
                    self::SCALE,
                );

                $quantities[$branch->getKey()] = $qty;
                $rowTotal = bcadd($rowTotal, $qty, self::SCALE);
                $totals[$branch->getKey()] = bcadd($totals[$branch->getKey()], $qty, self::SCALE);
            }

            $totals['total'] = bcadd($totals['total'], $rowTotal, self::SCALE);

            $rows[] = [
                'product_id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->displayName(),
                'quantities' => $quantities,
                'total' => $rowTotal,
            ];
        }

        return ['branches' => $branches, 'rows' => $rows, 'totals' => $totals];
    }
}
