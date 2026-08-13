<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tax;
use App\Services\Accounting\DocumentNumberAllocator;
use Illuminate\Support\Facades\DB;

/**
 * Fills in what a product can work out for itself.
 *
 * Qoyod requires a serial number, a category and a tax on every product, and
 * supplies all three: a generate button beside the serial, الصنف الأساسي
 * preselected, and the default rate chosen. Requiring them while refusing to
 * supply them would be a worse screen, so the same defaults are applied here —
 * in the model layer, so an import or a seeder gets them too.
 */
final class ProductObserver
{
    public function __construct(
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    public function creating(Product $product): void
    {
        if (blank($product->sku)) {
            $product->sku = $this->allocateSku();
        }

        if (blank($product->category_id)) {
            $product->category_id = ProductCategory::query()
                ->where('is_default', true)
                ->value('id');
        }

        if (blank($product->tax_id)) {
            $product->tax_id = Tax::query()->where('is_default', true)->value('id');
        }
    }

    public function saving(Product $product): void
    {
        $this->clearUnusedPrices($product);
    }

    /**
     * The next serial in the product series.
     *
     * Qoyod generates one behind a button but does not show its format, so the
     * shape here is this platform's own. The allocator refuses to run outside a
     * transaction, and a product created from a form is not in one.
     */
    private function allocateSku(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'product',
            defaults: ['prefix' => 'P', 'padding' => 4],
        ));
    }

    /**
     * A price for a side the product is not on is not a price.
     *
     * Qoyod hides the selling price until يُباع is ticked. Keeping a stale
     * figure behind an unticked box would let it reappear — and be invoiced —
     * the moment someone ticked it again.
     */
    private function clearUnusedPrices(Product $product): void
    {
        if (! $product->is_sold) {
            $product->selling_price = null;
        }

        if (! $product->is_purchased) {
            $product->buying_price = null;
        }
    }
}
