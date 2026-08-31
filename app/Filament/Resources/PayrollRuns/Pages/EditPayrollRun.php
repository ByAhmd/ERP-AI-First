<?php

declare(strict_types=1);

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Resources\PayrollRuns\Widgets\PayrollRunPreviewWidget;
use App\Models\PayrollRun;
use App\Models\PayrollRunExclusion;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\Exceptions\PayrollRunRejected;
use App\Services\Payroll\PayrollRunEngine;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPayrollRun extends EditRecord
{
    protected static string $resource = PayrollRunResource::class;

    /**
     * An approved run is immutable — correction is a reversal.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var PayrollRun $run */
        $run = $this->getRecord();

        if (! $run->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $run]));
        }
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        $this->data['excluded_employee_ids'] = PayrollRunExclusion::query()
            ->where('payroll_run_id', $this->getRecord()->getKey())
            ->pluck('employee_id')
            ->all();
    }

    protected function afterSave(): void
    {
        $runId = $this->getRecord()->getKey();

        PayrollRunExclusion::query()->where('payroll_run_id', $runId)->delete();

        foreach ((array) ($this->data['excluded_employee_ids'] ?? []) as $employeeId) {
            PayrollRunExclusion::create([
                'payroll_run_id' => $runId,
                'employee_id' => $employeeId,
            ]);
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getFooterWidgets(): array
    {
        return [
            PayrollRunPreviewWidget::class,
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('payroll.runs.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('payroll.runs.actions.approve_confirm'))
                ->action(function (PayrollRunEngine $engine): void {
                    $this->save(shouldRedirect: false);

                    /** @var PayrollRun $run */
                    $run = $this->getRecord()->refresh();

                    try {
                        $approved = $engine->approve($run, Filament::auth()->id());
                    } catch (PayrollRunRejected|PostingRejected $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('payroll.runs.actions.approved'))
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
            ->label(__('payroll.runs.actions.save_draft'));
    }
}
