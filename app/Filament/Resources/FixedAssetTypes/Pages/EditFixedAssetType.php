<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetTypes\Pages;

use App\Filament\Resources\FixedAssetTypes\FixedAssetTypeResource;
use App\Models\FixedAssetType;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFixedAssetType extends EditRecord
{
    protected static string $resource = FixedAssetTypeResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(function (): bool {
                    /** @var FixedAssetType $type */
                    $type = $this->getRecord();

                    return ! $type->assets()->exists();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
