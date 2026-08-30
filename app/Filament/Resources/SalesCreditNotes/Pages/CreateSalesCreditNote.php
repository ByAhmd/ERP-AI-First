<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesCreditNotes\Pages;

use App\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use App\Models\SalesCreditNote;
use App\Services\Sales\CreditNotePoster;
use App\Services\Sales\CreditNoteRecalculator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesCreditNote extends CreateRecord
{
    protected static string $resource = SalesCreditNoteResource::class;

    /**
     * A new credit note is a draft. Approving is the deliberate act that
     * reaches the ledger, exactly as it is for the invoice.
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
        /** @var SalesCreditNote $record */
        $record = $this->getRecord();

        app(CreditNoteRecalculator::class)->recalculate($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * A reference from the moment the form opens, as Qoyod shows one.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(CreditNotePoster::class)->nextReference();
        }
    }
}
