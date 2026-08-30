<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReceipts\Pages;

use App\Filament\Resources\CustomerReceipts\CustomerReceiptResource;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\SalesInvoice;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Sales\CustomerReceiptPoster;
use App\Services\Sales\Exceptions\ReceiptRejected;
use App\Services\Sales\InvoiceOutstanding;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomerReceipt extends ViewRecord
{
    protected static string $resource = CustomerReceiptResource::class;

    /**
     * Moving the advance after approval.
     *
     * Each movement is its own accounting event through the poster — an entry
     * at its own date, with the receipt's original entry untouched. What the
     * options show as outstanding is advisory; the binding figure is computed
     * under lock inside the poster.
     *
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('allocate')
                ->label(__('sales.receipts.allocations.allocate'))
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->visible(fn (CustomerReceipt $record): bool => $record->isApproved()
                    && bccomp($record->unallocatedAmount(), '0', 4) > 0)
                ->schema([
                    Select::make('sales_invoice_id')
                        ->label(__('sales.receipts.allocations.invoice'))
                        ->options(function (CustomerReceipt $record): array {
                            $outstanding = app(InvoiceOutstanding::class);

                            return SalesInvoice::query()
                                ->approved()
                                ->where('contact_id', $record->contact_id)
                                ->orderByDesc('issue_date')
                                ->get()
                                ->mapWithKeys(fn (SalesInvoice $i): array => [
                                    $i->getKey() => $i->reference
                                        .' — '.__('sales.receipts.allocations.outstanding')
                                        .' '.number_format((float) $outstanding->outstanding($i), 2),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('sales.receipts.allocations.amount'))
                        ->numeric()
                        ->required(),

                    DatePicker::make('date')
                        ->label(__('sales.receipts.allocations.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),
                ])
                ->action(function (array $data, CustomerReceipt $record, CustomerReceiptPoster $poster): void {
                    $invoice = SalesInvoice::query()->find($data['sales_invoice_id']);

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
                    } catch (ReceiptRejected|PostingRejected $refusal) {
                        Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.receipts.allocations.allocated'))
                        ->success()
                        ->send();
                }),

            Action::make('unallocate')
                ->label(__('sales.receipts.allocations.unallocate'))
                ->icon(Heroicon::OutlinedArrowUturnRight)
                ->color('gray')
                ->visible(fn (CustomerReceipt $record): bool => $record->isApproved()
                    && $record->allocations()->exists())
                ->schema([
                    Select::make('allocation_id')
                        ->label(__('sales.receipts.allocations.invoice'))
                        ->options(fn (CustomerReceipt $record): array => $record->allocations()
                            ->with('invoice')
                            ->get()
                            ->mapWithKeys(fn (CustomerReceiptAllocation $a): array => [
                                $a->getKey() => ($a->invoice === null ? '—' : $a->invoice->reference)
                                    .' — '.number_format((float) $a->amount, 2),
                            ])
                            ->all())
                        ->required(),

                    DatePicker::make('date')
                        ->label(__('sales.receipts.allocations.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),
                ])
                ->action(function (array $data, CustomerReceipt $record, CustomerReceiptPoster $poster): void {
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
                    } catch (ReceiptRejected|PostingRejected $refusal) {
                        Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sales.receipts.allocations.unallocated_done'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
