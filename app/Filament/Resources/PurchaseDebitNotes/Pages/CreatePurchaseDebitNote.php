<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseDebitNotes\Pages;

use App\Filament\Resources\PurchaseDebitNotes\PurchaseDebitNoteResource;
use App\Models\PurchaseDebitNote;
use App\Services\Purchases\DebitNotePoster;
use App\Services\Purchases\DebitNoteRecalculator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseDebitNote extends CreateRecord
{
    protected static string $resource = PurchaseDebitNoteResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var PurchaseDebitNote $record */
        $record = $this->getRecord();

        app(DebitNoteRecalculator::class)->recalculate($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(DebitNotePoster::class)->nextReference();
        }
    }
}
