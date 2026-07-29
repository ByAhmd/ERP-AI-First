<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Pages;

use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Accounting\JournalPoster;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditJournalEntry extends EditRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveAndPost')
                ->label(__('accounting.entries.actions.save_and_post'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('accounting.entries.actions.post_hint'))
                ->action(function (JournalPoster $poster): void {
                    // Save first: posting validates what is stored, and an
                    // unsaved edit would otherwise be silently discarded.
                    $this->save(shouldRedirect: false);

                    /** @var JournalEntry $record */
                    $record = $this->getRecord()->refresh();

                    try {
                        $posted = $poster->postDraft($record, Filament::auth()->id());
                    } catch (PostingRejected $e) {
                        Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('accounting.entries.notifications.posted', ['number' => $posted->number]))
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $posted]));
                }),

            DeleteAction::make(),
        ];
    }
}
