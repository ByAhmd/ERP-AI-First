<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeBonuses\Pages;

use App\Filament\Resources\EmployeeBonuses\EmployeeBonusResource;
use App\Models\EmployeeBonus;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\EmployeeBonusPoster;
use App\Services\Payroll\Exceptions\PayrollRuleViolation;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditEmployeeBonus extends EditRecord
{
    protected static string $resource = EmployeeBonusResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var EmployeeBonus $bonus */
        $bonus = $this->getRecord();

        if (! $bonus->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $bonus]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('payroll.bonuses.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('payroll.bonuses.actions.approve_confirm'))
                ->action(function (EmployeeBonusPoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var EmployeeBonus $bonus */
                    $bonus = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($bonus, Filament::auth()->id());
                    } catch (PayrollRuleViolation|PostingRejected $refusal) {
                        Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title(__('payroll.bonuses.actions.approved'))->success()->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('payroll.bonuses.actions.save_draft'));
    }
}
