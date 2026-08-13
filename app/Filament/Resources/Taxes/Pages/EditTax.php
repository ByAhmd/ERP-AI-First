<?php

declare(strict_types=1);

namespace App\Filament\Resources\Taxes\Pages;

use App\Filament\Resources\Taxes\TaxResource;
use App\Models\Tax;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTax extends EditRecord
{
    protected static string $resource = TaxResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Hidden rather than left to fail: the observer refuses to delete a
            // seeded rate, and offering a button that always errors is a worse
            // way to say so.
            DeleteAction::make()
                ->hidden(fn (Tax $record): bool => $record->is_system),
        ];
    }
}
