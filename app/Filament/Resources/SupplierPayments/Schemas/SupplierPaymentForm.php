<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments\Schemas;

use App\Models\Account;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\BillOutstanding;
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
 * The payment voucher form — Qoyod's سند صرف field set.
 *
 * The receipt form's mirror: a supplier picker instead of a customer one,
 * a payment account gated by the same يمكن الدفع والتحصيل flag, and an
 * allocations table listing the supplier's approved bills with what is
 * still open on each. The figures shown are advisory — the binding ones
 * are computed under lock at approval.
 */
class SupplierPaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('purchases.payments.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('purchases.payments.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('contact_id')
                        ->label(__('purchases.payments.fields.contact'))
                        ->options(fn (): array => Contact::query()
                            ->suppliers()
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

                    Select::make('payment_account_id')
                        ->label(__('purchases.payments.fields.payment_account'))
                        ->helperText(__('purchases.payments.hints.payment_account'))
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

                    DatePicker::make('payment_date')
                        ->label(__('purchases.payments.fields.payment_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('purchases.payments.fields.amount'))
                        ->helperText(__('purchases.payments.hints.unallocated'))
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
                        ->label(__('purchases.payments.fields.description'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('purchases.payments.allocations.title'))
                ->schema([
                    Repeater::make('allocations')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('purchases.payments.allocations.invoice'))->width('60%'),
                            TableColumn::make(__('purchases.payments.allocations.amount'))->width('40%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('purchase_invoice_id')
                                // Only this supplier's approved bills, each
                                // labelled with what is still open on it.
                                ->options(function (Get $get): array {
                                    $contactId = $get('../../contact_id');

                                    if (blank($contactId)) {
                                        return [];
                                    }

                                    $outstanding = app(BillOutstanding::class);

                                    return PurchaseInvoice::query()
                                        ->approved()
                                        ->where('contact_id', $contactId)
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
                                ->required()
                                ->distinct(),

                            TextInput::make('amount')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->live(onBlur: true),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel(__('purchases.payments.allocations.add'))
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

                            return __('purchases.payments.allocations.summary', [
                                'allocated' => number_format((float) $allocated, 2),
                                'unallocated' => number_format(max(0, (float) $amount - (float) $allocated), 2),
                            ]);
                        }),
                ]),

            Section::make(__('purchases.invoices.sections.notes'))
                ->schema([
                    Textarea::make('notes')
                        ->label(__('purchases.invoices.fields.notes')),
                ])
                ->collapsed(),
        ]);
    }
}
