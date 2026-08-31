<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetDisposals\Pages;

use App\Filament\Resources\FixedAssetDisposals\FixedAssetDisposalResource;
use App\Models\FixedAssetDisposal;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Assets\AssetDisposalPoster;
use App\Services\Assets\Exceptions\AssetRuleViolation;
use App\Services\Assets\Exceptions\DisposalRejected;
use App\Services\Assets\Exceptions\RunRejected;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditFixedAssetDisposal extends EditRecord
{
    protected static string $resource = FixedAssetDisposalResource::class;

    /**
     * An approved disposal is history — reading only.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var FixedAssetDisposal $disposal */
        $disposal = $this->getRecord();

        if (! $disposal->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $disposal]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('assets.disposals.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('assets.disposals.actions.approve_confirm'))
                ->action(function (AssetDisposalPoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var FixedAssetDisposal $disposal */
                    $disposal = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($disposal, Filament::auth()->id());
                    } catch (DisposalRejected|AssetRuleViolation|RunRejected|PostingRejected $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('assets.disposals.actions.approved'))
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
            ->label(__('assets.disposals.actions.save_draft'));
    }
}
