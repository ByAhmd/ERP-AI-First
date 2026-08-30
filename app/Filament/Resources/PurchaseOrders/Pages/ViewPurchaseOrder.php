<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\Purchases\Exceptions\PurchaseOrderRuleViolation;
use App\Services\Purchases\PurchaseOrderApprover;
use App\Services\Purchases\PurchaseOrderConverter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // تحويل لفاتورة — the order's one exit into the books.
            Action::make('convert')
                ->label(__('purchases.orders.actions.convert'))
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('primary')
                ->visible(fn (): bool => $this->order()->isApproved())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->order()->isOverdue()
                    ? __('purchases.orders.actions.convert_overdue_warning', [
                        'date' => $this->order()->expiry_date->format('d M Y'),
                    ])
                    : __('purchases.orders.actions.convert_confirm'))
                ->action(function (PurchaseOrderConverter $converter): void {
                    try {
                        $invoice = $converter->convert($this->order(), Filament::auth()->id());
                    } catch (PurchaseOrderRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.orders.actions.converted', [
                            'reference' => $invoice->reference,
                        ]))
                        ->success()
                        ->send();

                    $this->redirect(PurchaseInvoiceResource::getUrl('edit', ['record' => $invoice]));
                }),

            Action::make('cancel')
                ->label(__('purchases.orders.actions.cancel'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => in_array(
                    $this->order()->status,
                    [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved],
                    true,
                ))
                ->requiresConfirmation()
                ->modalDescription(__('purchases.orders.actions.cancel_confirm'))
                ->action(function (PurchaseOrderApprover $approver): void {
                    try {
                        $approver->cancel($this->order());
                    } catch (PurchaseOrderRuleViolation $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.orders.actions.cancelled'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
        ];
    }

    private function order(): PurchaseOrder
    {
        /** @var PurchaseOrder $record */
        $record = $this->getRecord();

        return $record;
    }
}
