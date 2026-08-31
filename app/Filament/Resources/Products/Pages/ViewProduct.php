<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The product view — Qoyod's عرض screen.
 *
 * The form renders read-only; the stock story lives in the relation
 * managers beneath it: quantities per branch, and the movement stream with
 * its running balances.
 */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Product $product */
        $product = $this->getRecord();

        if (! $product->track_inventory) {
            return null;
        }

        $cost = $product->costRecord;

        if ($cost === null) {
            return null;
        }

        return __('inventory.stock.summary', [
            'quantity' => number_format((float) $cost->quantity_on_hand, 2),
            'average' => number_format((float) $cost->average_cost, 2),
            'value' => number_format((float) $cost->total_value, 2),
        ]);
    }
}
