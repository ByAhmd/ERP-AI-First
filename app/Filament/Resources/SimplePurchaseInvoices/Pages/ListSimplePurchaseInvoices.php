<?php

declare(strict_types=1);

namespace App\Filament\Resources\SimplePurchaseInvoices\Pages;

use App\Filament\Resources\SimplePurchaseInvoices\SimplePurchaseInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSimplePurchaseInvoices extends ListRecords
{
    protected static string $resource = SimplePurchaseInvoiceResource::class;

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
