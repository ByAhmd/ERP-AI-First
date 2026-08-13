<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Soft delete. A customer that has been invoiced can never really
            // be removed — the invoice has to keep naming someone — so this
            // retires the record rather than erasing it.
            DeleteAction::make(),
        ];
    }
}
