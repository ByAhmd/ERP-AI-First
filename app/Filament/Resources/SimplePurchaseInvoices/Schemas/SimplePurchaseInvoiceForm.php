<?php

declare(strict_types=1);

namespace App\Filament\Resources\SimplePurchaseInvoices\Schemas;

use App\Enums\DiscountType;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Tax;
use App\Services\Sales\Data\LineAmounts;
use App\Services\Sales\InvoiceCalculator;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

/**
 * The simple bill form.
 *
 * Qoyod's فاتورة بسيطة: a quick expense keyed straight to accounts — the
 * line is a statement, an account, a value and a tax, with the quantity
 * fixed at one behind the scenes. No products, no due date, no payment
 * section (payment flows through سندات الموردين).
 */
class SimplePurchaseInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('purchases.invoices.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('purchases.invoices.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('contact_id')
                        ->label(__('purchases.invoices.fields.contact'))
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
                        ->required(),

                    TextInput::make('supplier_invoice_number')
                        ->label(__('purchases.invoices.fields.supplier_invoice_number'))
                        ->maxLength(100),

                    DatePicker::make('issue_date')
                        ->label(__('purchases.invoices.fields.issue_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('description')
                        ->label(__('purchases.invoices.fields.description'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('purchases.invoices.sections.items'))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('purchases.invoices.items.expense_account'))->width('26%'),
                            TableColumn::make(__('purchases.simple_invoices.fields.statement'))->width('26%'),
                            TableColumn::make(__('purchases.simple_invoices.fields.value'))->width('12%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.is_inclusive'))->width('8%')->alignCenter(),
                            TableColumn::make(__('sales.invoices.items.tax_rate'))->width('12%'),
                            TableColumn::make(__('sales.invoices.items.tax_amount'))->width('8%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.line_total'))->width('8%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('expense_account_id')
                                ->options(fn (): array => self::expenseAccounts())
                                ->searchable()
                                ->required(),

                            TextInput::make('product_description')
                                ->required(),

                            TextInput::make('unit_price')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required()
                                ->live(onBlur: true),

                            Toggle::make('is_inclusive')
                                ->inline(false)
                                ->live(),

                            Select::make('tax_id')
                                ->options(fn (): array => self::taxes())
                                ->default(fn (): ?string => Tax::query()->where('is_default', true)->value('id'))
                                ->live(),

                            TextInput::make('tax_amount')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function (Get $get): string {
                                    $amounts = self::compute($get);

                                    return $amounts === null ? '0.0000' : $amounts->taxAmount;
                                }),

                            TextInput::make('line_total')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function (Get $get): string {
                                    $amounts = self::compute($get);

                                    return $amounts === null ? '0.0000' : $amounts->lineTotal;
                                }),

                            // A value line: one of whatever the statement says.
                            Hidden::make('quantity')->default(1),
                        ])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel(__('sales.invoices.items.add'))
                        ->reorderable(false)
                        ->live()
                        ->helperText(fn (Get $get): string => self::totalsSummary($get('items') ?? [])),
                ]),

            Section::make(__('purchases.invoices.sections.notes'))
                ->schema([
                    Textarea::make('notes')
                        ->label(__('purchases.invoices.fields.notes')),
                ])
                ->collapsed(),
        ]);
    }

    private static function compute(Get $get): ?LineAmounts
    {
        try {
            return app(InvoiceCalculator::class)->line(
                quantity: '1',
                unitPrice: (string) ($get('unit_price') ?? '0'),
                isInclusive: (bool) $get('is_inclusive'),
                discountType: DiscountType::Percentage,
                discountValue: '0',
                taxRate: self::rateOf($get('tax_id')),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     */
    private static function totalsSummary(array $items): string
    {
        $calculator = app(InvoiceCalculator::class);
        $lines = [];

        foreach ($items as $item) {
            try {
                $lines[] = $calculator->line(
                    quantity: '1',
                    unitPrice: (string) ($item['unit_price'] ?? '0'),
                    isInclusive: (bool) ($item['is_inclusive'] ?? false),
                    discountType: DiscountType::Percentage,
                    discountValue: '0',
                    taxRate: self::rateOf($item['tax_id'] ?? null),
                    taxCategory: self::categoryOf($item['tax_id'] ?? null),
                );
            } catch (Throwable) {
                // A half-filled row contributes nothing until it is valid.
            }
        }

        $totals = $calculator->document($lines);

        return __('sales.invoices.totals.summary', [
            'net' => number_format((float) $totals->subtotalNet, 2),
            'tax' => number_format((float) $totals->taxTotal, 2),
            'total' => number_format((float) $totals->total, 2),
        ]);
    }

    private static function rateOf(mixed $taxId): string
    {
        if (blank($taxId) || ! is_string($taxId)) {
            return '0';
        }

        $tax = Tax::query()->find($taxId);

        return $tax === null ? '0' : (string) $tax->rate;
    }

    private static function categoryOf(mixed $taxId): ?TaxCategory
    {
        if (blank($taxId) || ! is_string($taxId)) {
            return null;
        }

        return Tax::query()->find($taxId)?->category;
    }

    /**
     * @return array<string, string>
     */
    private static function expenseAccounts(): array
    {
        return Account::query()
            ->where('is_postable', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $a): array => [
                $a->getKey() => $a->code.' - '.$a->name,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function taxes(): array
    {
        return Tax::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (Tax $t): array => [$t->getKey() => $t->displayName()])
            ->all();
    }
}
