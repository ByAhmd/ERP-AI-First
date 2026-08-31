<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryTransfers\Pages;

use App\Filament\Resources\InventoryTransfers\InventoryTransferResource;
use App\Services\Inventory\InventoryTransferPoster;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateInventoryTransfer extends CreateRecord
{
    protected static string $resource = InventoryTransferResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(InventoryTransferPoster::class)->nextReference();
        }
    }
}
