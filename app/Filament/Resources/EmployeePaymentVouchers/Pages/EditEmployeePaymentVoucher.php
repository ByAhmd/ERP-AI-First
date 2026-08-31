<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeePaymentVouchers\Pages;

use App\Filament\Resources\EmployeePaymentVouchers\EmployeePaymentVoucherResource;
use App\Models\EmployeePaymentVoucher;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\EmployeePaymentPoster;
use App\Services\Payroll\Exceptions\PayrollRuleViolation;
use App\Services\Payroll\Exceptions\VoucherRejected;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditEmployeePaymentVoucher extends EditRecord
{
    protected static string $resource = EmployeePaymentVoucherResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var EmployeePaymentVoucher $voucher */
        $voucher = $this->getRecord();

        if (! $voucher->isDraft()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $voucher]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('payroll.vouchers.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('payroll.vouchers.actions.approve_confirm'))
                ->action(function (EmployeePaymentPoster $poster): void {
                    $this->save(shouldRedirect: false);

                    /** @var EmployeePaymentVoucher $voucher */
                    $voucher = $this->getRecord()->refresh();

                    try {
                        $approved = $poster->approve($voucher, Filament::auth()->id());
                    } catch (VoucherRejected|PayrollRuleViolation|PostingRejected $refusal) {
                        Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title(__('payroll.vouchers.actions.approved'))->success()->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $approved]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(__('payroll.vouchers.actions.save_draft'));
    }
}
