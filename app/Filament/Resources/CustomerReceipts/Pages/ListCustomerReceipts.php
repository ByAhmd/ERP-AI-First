<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReceipts\Pages;

use App\Filament\Resources\CustomerReceipts\CustomerReceiptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerReceipts extends ListRecords
{
    protected static string $resource = CustomerReceiptResource::class;

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
