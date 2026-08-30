<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesQuotations\Pages;

use App\Enums\QuotationStatus;
use App\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Filament\Resources\SalesQuotations\SalesQuotationResource;
use App\Models\SalesQuotation;
use App\Services\Sales\Exceptions\QuotationRuleViolation;
use App\Services\Sales\QuotationConverter;
use App\Services\Sales\SalesQuotationApprover;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSalesQuotation extends ViewRecord
{
    protected static string $resource = SalesQuotationResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // تحويل لفاتورة — the quotation's one exit into the books, and
            // only from Approved.
            Action::make('convert')
                ->label(__('sales.quotations.actions.convert'))
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('primary')
                ->visible(fn (): bool => $this->quotation()->isApproved())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->quotation()->isExpired()
                    ? __('sales.quotations.actions.convert_expired_warning', [
                        'date' => $this->quotation()->expiry_date->format('d M Y'),
                    ])
                    : __('sales.quotations.actions.convert_confirm'))
                ->action(function (QuotationConverter $converter): void {
                    try {
                        $invoice = $converter->convert($this->quotation(), Filament::auth()->id());
                    } catch (QuotationRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.quotations.actions.converted', [
                            'reference' => $invoice->reference,
                        ]))
                        ->success()
                        ->send();

                    // Qoyod lands you on the pre-filled invoice to review.
                    $this->redirect(SalesInvoiceResource::getUrl('edit', ['record' => $invoice]));
                }),

            Action::make('cancel')
                ->label(__('sales.quotations.actions.cancel'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => in_array(
                    $this->quotation()->status,
                    [QuotationStatus::Draft, QuotationStatus::Approved],
                    true,
                ))
                ->requiresConfirmation()
                ->modalDescription(__('sales.quotations.actions.cancel_confirm'))
                ->action(function (SalesQuotationApprover $approver): void {
                    try {
                        $approver->cancel($this->quotation());
                    } catch (QuotationRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.quotations.actions.cancelled'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
        ];
    }

    private function quotation(): SalesQuotation
    {
        /** @var SalesQuotation $record */
        $record = $this->getRecord();

        return $record;
    }
}
