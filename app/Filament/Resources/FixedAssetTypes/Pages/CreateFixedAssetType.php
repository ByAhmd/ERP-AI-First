<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetTypes\Pages;

use App\Filament\Resources\FixedAssetTypes\FixedAssetTypeResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateFixedAssetType extends CreateRecord
{
    protected static string $resource = FixedAssetTypeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
