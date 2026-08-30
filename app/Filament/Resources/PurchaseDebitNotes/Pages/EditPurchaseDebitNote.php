<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseDebitNotes\Pages;

use App\Filament\Resources\PurchaseDebitNotes\PurchaseDebitNoteResource;
use App\Models\PurchaseDebitNote;
use App\Services\Purchases\DebitNotePoster;
use App\Services\Purchases\DebitNoteRecalculator;
use App\Services\Purchases\Exceptions\DebitNoteRejected;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPurchaseDebitNote extends EditRecord
{
    protected static string $resource = PurchaseDebitNoteResource::class;

    /**
     * An approved note is not editable.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var PurchaseDebitNote $note */
        $note = $this->getRecord();

        if (! $note->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $note]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('purchases.debit_notes.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('purchases.debit_notes.actions.approve_confirm'))
                ->action(function (DebitNotePoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var PurchaseDebitNote $note */
                    $note = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($note, Filament::auth()->id());
                    } catch (DebitNoteRejected $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.debit_notes.actions.approved'))
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var PurchaseDebitNote $note */
        $note = $this->getRecord();

        app(DebitNoteRecalculator::class)->recalculate($note);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('purchases.debit_notes.actions.save_draft'));
    }
}
