<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesCreditNotes\Pages;

use App\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesCreditNotes extends ListRecords
{
    protected static string $resource = SalesCreditNoteResource::class;

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
