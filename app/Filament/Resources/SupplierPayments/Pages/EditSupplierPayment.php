<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments\Pages;

use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\SupplierPayment;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Purchases\Exceptions\PaymentRejected;
use App\Services\Purchases\SupplierPaymentPoster;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSupplierPayment extends EditRecord
{
    protected static string $resource = SupplierPaymentResource::class;

    /**
     * An approved voucher opens read-only; the URL can still be typed.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SupplierPayment $payment */
        $payment = $this->getRecord();

        if (! $payment->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $payment]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('purchases.payments.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('purchases.payments.actions.approve_confirm'))
                ->action(function (SupplierPaymentPoster $poster): void {
                    // Save first: approving reads what is stored.
                    $this->save(shouldRedirect: false);

                    /** @var SupplierPayment $payment */
                    $payment = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($payment, Filament::auth()->id());
                    } catch (PaymentRejected|PostingRejected $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.payments.actions.approved'))
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
            ->label(__('purchases.payments.actions.save_draft'));
    }
}
