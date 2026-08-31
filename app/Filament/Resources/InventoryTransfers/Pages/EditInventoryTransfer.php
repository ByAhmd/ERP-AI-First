<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryTransfers\Pages;

use App\Filament\Resources\InventoryTransfers\InventoryTransferResource;
use App\Models\InventoryTransfer;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use App\Services\Inventory\Exceptions\TransferRejected;
use App\Services\Inventory\InventoryTransferPoster;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditInventoryTransfer extends EditRecord
{
    protected static string $resource = InventoryTransferResource::class;

    /**
     * An approved transfer moved real goods; it opens read-only.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var InventoryTransfer $transfer */
        $transfer = $this->getRecord();

        if (! $transfer->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $transfer]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Qoyod's إرسال واستقبال, one step: quantities leave the source
            // and arrive at the destination inside one transaction.
            Action::make('approve')
                ->label(__('inventory.transfers.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('inventory.transfers.actions.approve_confirm'))
                ->action(function (InventoryTransferPoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var InventoryTransfer $transfer */
                    $transfer = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($transfer, Filament::auth()->id());
                    } catch (TransferRejected|StockRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('inventory.transfers.actions.approved'))
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
            ->label(__('inventory.transfers.actions.save_draft'));
    }
}
