<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInvoices\Pages;

use App\Enums\PurchaseInvoiceKind;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    /**
     * A new bill is a draft, and this screen only makes standard bills —
     * the kind is stamped, not asked, exactly as the supplier screen stamps
     * its contact type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();
        $data['kind'] = PurchaseInvoiceKind::Standard;

        return $data;
    }

    /**
     * Totals are derived, never typed.
     */
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
     * The reference shown from the moment the form opens — the BIL series,
     * never the sales counter.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(PurchaseInvoicePoster::class)->nextReference();
        }
    }
}
