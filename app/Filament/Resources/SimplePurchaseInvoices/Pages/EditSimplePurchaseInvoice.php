<?php

declare(strict_types=1);

namespace App\Filament\Resources\SimplePurchaseInvoices\Pages;

use App\Filament\Resources\SimplePurchaseInvoices\SimplePurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\Exceptions\PurchaseInvoiceRuleViolation;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSimplePurchaseInvoice extends EditRecord
{
    protected static string $resource = SimplePurchaseInvoiceResource::class;

    /**
     * An approved bill is not editable — correction is by debit note.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var PurchaseInvoice $invoice */
        $invoice = $this->getRecord();

        if (! $invoice->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $invoice]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('purchases.invoices.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('purchases.invoices.actions.approve_confirm'))
                ->action(function (PurchaseInvoicePoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var PurchaseInvoice $invoice */
                    $invoice = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($invoice, Filament::auth()->id());
                    } catch (PurchaseInvoiceRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.invoices.actions.approved'))
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var PurchaseInvoice $invoice */
        $invoice = $this->getRecord();

        app(PurchaseInvoiceRecalculator::class)->recalculate($invoice);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('purchases.invoices.actions.save_draft'));
    }
}
