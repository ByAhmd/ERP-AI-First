<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments\Pages;

use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Purchases\BillOutstanding;
use App\Services\Purchases\Exceptions\PaymentRejected;
use App\Services\Purchases\SupplierPaymentPoster;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierPayment extends ViewRecord
{
    protected static string $resource = SupplierPaymentResource::class;

    /**
     * Moving the advance after approval.
     *
     * Each movement is its own accounting event through the poster — an
     * entry at its own date, with the voucher's original entry untouched.
     *
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('allocate')
                ->label(__('purchases.payments.actions.allocate'))
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->visible(fn (SupplierPayment $record): bool => $record->isApproved()
                    && bccomp($record->unallocatedAmount(), '0', 4) > 0)
                ->schema([
                    Select::make('purchase_invoice_id')
                        ->label(__('purchases.payments.allocations.invoice'))
                        ->options(function (SupplierPayment $record): array {
                            $outstanding = app(BillOutstanding::class);

                            return PurchaseInvoice::query()
                                ->approved()
                                ->where('contact_id', $record->contact_id)
                                ->orderByDesc('issue_date')
                                ->get()
                                ->mapWithKeys(fn (PurchaseInvoice $i): array => [
                                    $i->getKey() => $i->reference
                                        .' — '.__('purchases.payments.allocations.outstanding')
                                        .' '.number_format((float) $outstanding->outstanding($i), 2),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('purchases.payments.allocations.amount'))
                        ->numeric()
                        ->required(),

                    DatePicker::make('date')
                        ->label(__('purchases.payments.allocations.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),
                ])
                ->action(function (array $data, SupplierPayment $record, SupplierPaymentPoster $poster): void {
                    $invoice = PurchaseInvoice::query()->find($data['purchase_invoice_id']);

                    if ($invoice === null) {
                        return;
                    }

                    try {
                        $poster->allocate(
                            $record,
                            $invoice,
                            (string) $data['amount'],
                            CarbonImmutable::parse($data['date']),
                            Filament::auth()->id(),
                        );
                    } catch (PaymentRejected|PostingRejected $refusal) {
                        Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.payments.actions.allocated'))
                        ->success()
                        ->send();
                }),

            Action::make('unallocate')
                ->label(__('purchases.payments.actions.unallocate'))
                ->icon(Heroicon::OutlinedArrowUturnRight)
                ->color('gray')
                ->visible(fn (SupplierPayment $record): bool => $record->isApproved()
                    && $record->allocations()->exists())
                ->schema([
                    Select::make('allocation_id')
                        ->label(__('purchases.payments.allocations.invoice'))
                        ->options(fn (SupplierPayment $record): array => $record->allocations()
                            ->with('invoice')
                            ->get()
                            ->mapWithKeys(fn (SupplierPaymentAllocation $a): array => [
                                $a->getKey() => ($a->invoice === null ? '—' : $a->invoice->reference)
                                    .' — '.number_format((float) $a->amount, 2),
                            ])
                            ->all())
                        ->required(),

                    DatePicker::make('date')
                        ->label(__('purchases.payments.allocations.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),
                ])
                ->action(function (array $data, SupplierPayment $record, SupplierPaymentPoster $poster): void {
                    $allocation = $record->allocations()->whereKey($data['allocation_id'])->first();

                    if ($allocation === null) {
                        return;
                    }

                    try {
                        $poster->unallocate(
                            $allocation,
                            CarbonImmutable::parse($data['date']),
                            Filament::auth()->id(),
                        );
                    } catch (PaymentRejected|PostingRejected $refusal) {
                        Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('purchases.payments.actions.unallocated_done'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
