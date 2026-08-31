<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetTypes\Pages;

use App\Filament\Resources\FixedAssetTypes\FixedAssetTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFixedAssetTypes extends ListRecords
{
    protected static string $resource = FixedAssetTypeResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
