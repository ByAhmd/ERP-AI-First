<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReceipts\Pages;

use App\Filament\Resources\CustomerReceipts\CustomerReceiptResource;
use App\Models\CustomerReceipt;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Sales\CustomerReceiptPoster;
use App\Services\Sales\Exceptions\ReceiptRejected;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCustomerReceipt extends EditRecord
{
    protected static string $resource = CustomerReceiptResource::class;

    /**
     * An approved receipt opens read-only; the URL can still be typed.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var CustomerReceipt $receipt */
        $receipt = $this->getRecord();

        if (! $receipt->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $receipt]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('sales.invoices.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('sales.receipts.actions.approve_confirm'))
                ->action(function (CustomerReceiptPoster $poster): void {
                    // Save first: approving reads what is stored.
                    $this->save(shouldRedirect: false);

                    /** @var CustomerReceipt $receipt */
                    $receipt = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($receipt, Filament::auth()->id());
                    } catch (ReceiptRejected|PostingRejected $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.receipts.actions.approved'))
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('sales.invoices.actions.save_draft'));
    }
}
