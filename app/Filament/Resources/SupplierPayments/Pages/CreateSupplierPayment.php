<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments\Pages;

use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Services\Purchases\SupplierPaymentPoster;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierPayment extends CreateRecord
{
    protected static string $resource = SupplierPaymentResource::class;

    /**
     * A new voucher is a draft. Approving is the deliberate act that posts.
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
     * A reference from the moment the form opens — the PYT series.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(SupplierPaymentPoster::class)->nextReference();
        }
    }
}
