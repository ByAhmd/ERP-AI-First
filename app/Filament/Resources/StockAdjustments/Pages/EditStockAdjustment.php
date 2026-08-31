<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Inventory\Exceptions\AdjustmentRejected;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use App\Services\Inventory\StockAdjustmentPoster;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditStockAdjustment extends EditRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    /**
     * An approved adjustment is immutable — correction is a
     * counter-adjustment.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var StockAdjustment $adjustment */
        $adjustment = $this->getRecord();

        if (! $adjustment->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $adjustment]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('inventory.adjustments.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('inventory.adjustments.actions.approve_confirm'))
                ->action(function (StockAdjustmentPoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var StockAdjustment $adjustment */
                    $adjustment = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($adjustment, Filament::auth()->id());
                    } catch (AdjustmentRejected|StockRuleViolation|PostingRejected $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('inventory.adjustments.actions.approved'))
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
            ->label(__('inventory.adjustments.actions.save_draft'));
    }
}
