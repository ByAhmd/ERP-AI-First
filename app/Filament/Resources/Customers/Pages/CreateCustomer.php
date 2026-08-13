<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Enums\ContactType;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * Stamp the type this resource represents.
     *
     * The form does not offer it — a screen headed "customers" that asks
     * whether the record is a customer is asking the user to restate the
     * obvious — but the column is not nullable and the resource filters on it,
     * so a record created without it would be invisible the moment it was
     * saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = ContactType::Customer;

        return $data;
    }
}
