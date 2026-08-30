<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReceipts\Schemas;

use App\Models\Account;
use App\Models\Contact;
use App\Models\SalesInvoice;
use App\Services\Sales\InvoiceOutstanding;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The receipt form — Qoyod's سند قبض field set.
 *
 * The deposit account select offers only accounts flagged for payments,
 * Qoyod's own `يمكن الدفع والتحصيل` rule. The poster re-checks it: a select
 * narrows choices, it does not enforce anything.
 *
 * Allocations are a table of the customer's approved invoices with their
 * outstanding figures beside them. What the form shows as outstanding is
 * advisory — the binding figure is computed under lock at approval.
 */
class CustomerReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('sales.invoices.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('sales.receipts.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('contact_id')
                        ->label(__('sales.receipts.fields.contact'))
                        ->options(fn (): array => Contact::query()
                            ->customers()
                            ->selectable()
                            ->orderBy('contact_name')
                            ->get()
                            ->mapWithKeys(fn (Contact $c): array => [
                                $c->getKey() => $c->displayName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live(),

                    Select::make('deposit_account_id')
                        ->label(__('sales.receipts.fields.deposit_account'))
                        ->helperText(__('sales.receipts.hints.deposit_account'))
                        ->options(fn (): array => Account::query()
                            ->where('is_payment_account', true)
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a): array => [
                                $a->getKey() => $a->code.' - '.$a->name,
                            ])
                            ->all())
                        ->searchable()
                        ->required(),

                    DatePicker::make('receipt_date')
                        ->label(__('sales.receipts.fields.receipt_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('sales.receipts.fields.amount'))
                        ->helperText(__('sales.receipts.hints.amount'))
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->live(onBlur: true),

                    TextInput::make('payment_method')
                        ->label(__('sales.receipts.fields.payment_method'))
                        ->maxLength(30),

                    TextInput::make('payment_reference')
                        ->label(__('sales.receipts.fields.payment_reference'))
                        ->maxLength(100),

                    TextInput::make('description')
                        ->label(__('sales.receipts.fields.description'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('sales.receipts.allocations.title'))
                ->schema([
                    Repeater::make('allocations')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('sales.receipts.allocations.invoice'))->width('60%'),
                            TableColumn::make(__('sales.receipts.allocations.amount'))->width('40%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('sales_invoice_id')
                                // Only this customer's approved invoices, each
                                // labelled with what is still outstanding on it.
                                ->options(function (Get $get): array {
                                    $contactId = $get('../../contact_id');

                                    if (blank($contactId)) {
                                        return [];
                                    }

                                    $outstanding = app(InvoiceOutstanding::class);

                                    return SalesInvoice::query()
                                        ->approved()
                                        ->where('contact_id', $contactId)
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
                                ->required()
                                ->distinct(),

                            TextInput::make('amount')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->live(onBlur: true),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel(__('sales.receipts.allocations.add'))
                        ->reorderable(false)
                        ->live()
                        ->helperText(function (Get $get): string {
                            $amount = (string) ($get('amount') ?? '0');
                            $amount = is_numeric($amount) ? $amount : '0';
                            $allocated = '0';

                            foreach ($get('allocations') ?? [] as $row) {
                                $value = (string) ($row['amount'] ?? '0');

                                if (is_numeric($value)) {
                                    $allocated = bcadd($allocated, $value, 4);
                                }
                            }

                            return __('sales.receipts.allocations.summary', [
                                'allocated' => number_format((float) $allocated, 2),
                                'unallocated' => number_format(max(0, (float) $amount - (float) $allocated), 2),
                            ]);
                        }),
                ]),

            Section::make(__('sales.invoices.sections.notes'))
                ->schema([
                    Textarea::make('notes')
                        ->label(__('sales.receipts.fields.notes')),
                ])
                ->collapsed(),
        ]);
    }
}
