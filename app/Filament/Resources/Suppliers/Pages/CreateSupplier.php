<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Pages;

use App\Enums\ContactType;
use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    /**
     * Stamp the type this resource represents.
     *
     * The form does not offer it — a screen headed "suppliers" that asks
     * whether the record is a supplier is asking the user to restate the
     * obvious — but the column is not nullable and the resource filters on it,
     * so a record created without it would be invisible the moment it was
     * saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = ContactType::Supplier;

        return $data;
    }
}
