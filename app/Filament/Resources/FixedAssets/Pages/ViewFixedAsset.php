<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Resources\FixedAssetDisposals\FixedAssetDisposalResource;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Resources\FixedAssets\Widgets\FixedAssetFiguresWidget;
use App\Models\FixedAsset;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The asset view — figures first, then the read-only form, with the posted
 * charges beneath. Disposal starts here, prefilled.
 */
class ViewFixedAsset extends ViewRecord
{
    protected static string $resource = FixedAssetResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            FixedAssetFiguresWidget::class,
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('dispose')
                ->label(__('assets.register.actions.dispose'))
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->color('danger')
                ->visible(function (): bool {
                    /** @var FixedAsset $asset */
                    $asset = $this->getRecord();

                    return $asset->isActive();
                })
                ->url(fn (): string => FixedAssetDisposalResource::getUrl('create', [
                    'fixed_asset_id' => $this->getRecord()->getKey(),
                ])),
        ];
    }
}
