<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetDisposals\Pages;

use App\Filament\Resources\FixedAssetDisposals\FixedAssetDisposalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFixedAssetDisposals extends ListRecords
{
    protected static string $resource = FixedAssetDisposalResource::class;

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
