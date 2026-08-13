<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesInvoices\Pages;

use App\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesInvoice extends CreateRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    /**
     * A new invoice is a draft.
     *
     * Approving is a separate, deliberate act: it posts to the ledger and
     * fixes the document. Making "save" mean "approve" would leave a user one
     * mistyped figure away from an entry that can only be undone by a credit
     * note.
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

    /**
     * Totals are derived, never typed, so they are resolved once the lines
     * exist rather than trusted from the form.
     */
    protected function afterCreate(): void
    {
        /** @var SalesInvoice $record */
        $record = $this->getRecord();

        app(SalesInvoiceRecalculator::class)->recalculate($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * The reference Qoyod shows from the moment the form opens.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(SalesInvoicePoster::class)->nextReference();
        }
    }
}
