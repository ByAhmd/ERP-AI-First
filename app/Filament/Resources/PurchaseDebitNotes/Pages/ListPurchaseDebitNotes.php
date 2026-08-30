<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseDebitNotes\Pages;

use App\Filament\Resources\PurchaseDebitNotes\PurchaseDebitNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseDebitNotes extends ListRecords
{
    protected static string $resource = PurchaseDebitNoteResource::class;

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
