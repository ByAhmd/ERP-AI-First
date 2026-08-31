<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\Widgets;

use App\Models\FixedAsset;
use App\Services\Assets\DepreciationEngine;
use Filament\Widgets\Widget;

/**
 * The asset's money story — Qoyod's ضبط القيمة panel.
 *
 * Cost, salvage, accumulated and book value from the POSTED figures, plus
 * the display-only forward schedule the engine projects. Posted rows are
 * facts; the projection is clearly a projection.
 */
class FixedAssetFiguresWidget extends Widget
{
    protected string $view = 'filament.assets.figures-widget';

    public ?FixedAsset $record = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $asset = $this->record;

        if ($asset === null) {
            return ['figures' => [], 'projection' => []];
        }

        $accumulated = $asset->accumulatedDepreciation();
        $book = $asset->bookValue();

        $projection = app(DepreciationEngine::class)->projection($asset);

        $unposted = '0.0000';

        foreach ($projection as $row) {
            $unposted = bcadd($unposted, $row['amount'], 4);
        }

        return [
            'figures' => [
                __('assets.register.figures.cost') => number_format((float) $asset->cost, 2),
                __('assets.register.figures.salvage') => number_format((float) $asset->salvage_value, 2),
                __('assets.register.figures.life') => $asset->useful_life_months !== null
                    ? (string) $asset->useful_life_months
                    : '—',
                __('assets.register.figures.accumulated') => number_format((float) $accumulated, 2),
                __('assets.register.figures.unposted') => number_format((float) $unposted, 2),
                __('assets.register.figures.book_value') => number_format((float) $book, 2),
            ],
            'projection' => $projection,
        ];
    }
}
