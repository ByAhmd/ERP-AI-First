<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesCreditNotes\Pages;

use App\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use App\Models\SalesCreditNote;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Sales\CreditNotePoster;
use App\Services\Sales\CreditNoteRecalculator;
use App\Services\Sales\Exceptions\CreditNoteRejected;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSalesCreditNote extends EditRecord
{
    protected static string $resource = SalesCreditNoteResource::class;

    /**
     * An approved note opens read-only; the URL can still be typed.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SalesCreditNote $note */
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
                ->label(__('sales.invoices.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('sales.credit_notes.actions.approve_confirm'))
                ->action(function (CreditNotePoster $poster): void {
                    // Save first: approving reads what is stored.
                    $this->save(shouldRedirect: false);

                    /** @var SalesCreditNote $note */
                    $note = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($note, Filament::auth()->id());
                    } catch (CreditNoteRejected|PostingRejected $refusal) {
                        // PostingRejected too: a credit note routinely corrects
                        // an invoice from a prior period, and a closed period
                        // must surface as a message rather than a 500.
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.credit_notes.actions.approved'))
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var SalesCreditNote $note */
        $note = $this->getRecord();

        app(CreditNoteRecalculator::class)->recalculate($note);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('sales.invoices.actions.save_draft'));
    }
}
