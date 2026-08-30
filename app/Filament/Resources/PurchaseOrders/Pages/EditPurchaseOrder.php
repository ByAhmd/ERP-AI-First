<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\Purchases\Exceptions\PurchaseOrderRuleViolation;
use App\Services\Purchases\PurchaseOrderApprover;
use App\Services\Purchases\PurchaseOrderRecalculator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /**
     * Anything past draft is not editable — approved went to the supplier,
     * billed is frozen provenance for the bill pointing at it.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var PurchaseOrder $order */
        $order = $this->getRecord();

        if (! $order->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $order]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // The modal says what approval does NOT do: no accounts move.
            Action::make('approve')
                ->label(__('purchases.orders.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('purchases.orders.actions.approve_confirm'))
                ->action(function (PurchaseOrderApprover $approver): void {
                    $this->save(shouldRedirect: false);

                    /** @var PurchaseOrder $order */
                    $order = $this->getRecord()->refresh();

                    try {
                        $approved = $approver->approve($order, Filament::auth()->id());
                    } catch (PurchaseOrderRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.orders.actions.approved'))
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var PurchaseOrder $order */
        $order = $this->getRecord();

        app(PurchaseOrderRecalculator::class)->recalculate($order);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('purchases.orders.actions.save_draft'));
    }
}
