<?php

declare(strict_types=1);

namespace App\Filament\Resources\SimplePurchaseInvoices\Pages;

use App\Enums\PurchaseInvoiceKind;
use App\Filament\Resources\SimplePurchaseInvoices\SimplePurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSimplePurchaseInvoice extends CreateRecord
{
    protected static string $resource = SimplePurchaseInvoiceResource::class;

    /**
     * This screen only makes simple bills — the kind is stamped, not asked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();
        $data['kind'] = PurchaseInvoiceKind::Simple;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var PurchaseInvoice $record */
        $record = $this->getRecord();

        app(PurchaseInvoiceRecalculator::class)->recalculate($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * The SB series — its own counter, as Qoyod numbers simple bills.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(PurchaseInvoicePoster::class)->nextSimpleReference();
        }
    }
}
