<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReceipts\Pages;

use App\Filament\Resources\CustomerReceipts\CustomerReceiptResource;
use App\Services\Sales\CustomerReceiptPoster;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerReceipt extends CreateRecord
{
    protected static string $resource = CustomerReceiptResource::class;

    /**
     * A new receipt is a draft. Approving is the deliberate act that posts.
     *
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

    /**
     * A reference from the moment the form opens.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(CustomerReceiptPoster::class)->nextReference();
        }
    }
}
