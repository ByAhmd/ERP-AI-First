<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesQuotations\Pages;

use App\Filament\Resources\SalesQuotations\SalesQuotationResource;
use App\Models\SalesQuotation;
use App\Services\Sales\SalesQuotationApprover;
use App\Services\Sales\SalesQuotationRecalculator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesQuotation extends CreateRecord
{
    protected static string $resource = SalesQuotationResource::class;

    /**
     * A new quotation is a draft. Approving is a separate act even though it
     * posts nothing — an approved quotation is the offer the customer holds,
     * and fixing it should be as deliberate as it is on the invoice.
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
        /** @var SalesQuotation $record */
        $record = $this->getRecord();

        app(SalesQuotationRecalculator::class)->recalculate($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * The reference Qoyod shows from the moment the form opens — from the
     * quotation's own QTE series, never the invoice counter.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if (blank($this->data['reference'] ?? null)) {
            $this->data['reference'] = app(SalesQuotationApprover::class)->nextReference();
        }
    }
}
