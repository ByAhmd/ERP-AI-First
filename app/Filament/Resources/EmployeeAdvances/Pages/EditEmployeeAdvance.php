<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Models\EmployeeAdvance;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\EmployeeAdvancePoster;
use App\Services\Payroll\Exceptions\PayrollRuleViolation;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditEmployeeAdvance extends EditRecord
{
    protected static string $resource = EmployeeAdvanceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var EmployeeAdvance $advance */
        $advance = $this->getRecord();

        if (! $advance->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $advance]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('payroll.advances.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('payroll.advances.actions.approve_confirm'))
                ->action(function (EmployeeAdvancePoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var EmployeeAdvance $advance */
                    $advance = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($advance, Filament::auth()->id());
                    } catch (PayrollRuleViolation|PostingRejected $refusal) {
                        Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title(__('payroll.advances.actions.approved'))->success()->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('payroll.advances.actions.save_draft'));
    }
}
