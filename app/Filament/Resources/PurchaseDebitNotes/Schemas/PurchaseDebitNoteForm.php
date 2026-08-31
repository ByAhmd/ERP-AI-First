<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseDebitNotes\Schemas;

use App\Enums\DiscountType;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Tax;
use App\Services\Purchases\BillOutstanding;
use App\Services\Sales\Data\LineAmounts;
use App\Services\Sales\InvoiceCalculator;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Throwable;

/**
 * The purchase debit note form.
 *
 * The bill form with a parent picker in place of the supplier-invoice
 * fields: choose the bill being corrected and the note inherits the
 * supplier's invoice identity from it. A note against a bill from a
 * predecessor system leaves the picker empty and types the external
 * reference instead — Qoyod's مرجع خارجي.
 *
 * No reason codes and no event date: ZATCA's Article 40 machinery binds
 * documents we issue as a seller.
 */
class PurchaseDebitNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('purchases.invoices.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('purchases.debit_notes.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('contact_id')
                        ->label(__('purchases.debit_notes.fields.contact'))
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

                    Select::make('parent_id')
                        ->label(__('purchases.debit_notes.fields.parent'))
                        ->helperText(__('purchases.debit_notes.hints.parent'))
                        // Only this supplier's approved bills, labelled with
                        // what is still open on each — the form avoids
                        // offering what the poster will refuse.
                        ->options(function (Get $get): array {
                            $contactId = $get('contact_id');

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
                                        .' — '.number_format((float) $outstanding->outstanding($i), 2),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->live()
                        // Choosing a bill fills the identity the note must
                        // carry — the SUPPLIER's number, not our BIL one.
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if (blank($state)) {
                                return;
                            }

                            $invoice = PurchaseInvoice::query()->find($state);

                            if ($invoice !== null) {
                                $set('original_invoice_number', $invoice->supplier_invoice_number ?? $invoice->reference);
                                $set('original_invoice_date', ($invoice->supplier_invoice_date ?? $invoice->issue_date)->toDateString());
                            }
                        }),

                    TextInput::make('original_invoice_number')
                        ->label(__('purchases.debit_notes.fields.original_invoice_number'))
                        ->helperText(__('purchases.debit_notes.hints.original_invoice_number'))
                        ->required()
                        ->maxLength(100),

                    DatePicker::make('original_invoice_date')
                        ->label(__('purchases.debit_notes.fields.original_invoice_date'))
                        ->native(false),

                    Toggle::make('returns_goods')
                        ->label(__('purchases.debit_notes.fields.returns_goods'))
                        ->helperText(__('inventory.hints.returns_goods'))
                        ->default(true)
                        ->inline(false),

                    DatePicker::make('issue_date')
                        ->label(__('purchases.debit_notes.fields.issue_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('description')
                        ->label(__('purchases.debit_notes.fields.description'))
                        ->maxLength(255),

                    Select::make('branch_id')
                        ->label(__('inventory.fields.branch'))
                        ->options(fn (): array => Branch::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Branch $b): array => [
                                $b->getKey() => $b->displayName(),
                            ])
                            ->all())
                        ->default(fn (): ?string => Branch::query()
                            ->where('is_default', true)->value('id'))
                        ->required(),
                ])
                ->columns(3),

            Section::make(__('purchases.invoices.sections.items'))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('sales.invoices.items.product'))->width('15%'),
                            TableColumn::make(__('sales.invoices.items.description'))->width('12%'),
                            TableColumn::make(__('purchases.invoices.items.expense_account'))->width('12%'),
                            TableColumn::make(__('sales.invoices.items.quantity'))->width('6%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.unit_price'))->width('9%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.is_inclusive'))->width('5%')->alignCenter(),
                            TableColumn::make(__('sales.invoices.items.discount'))->width('6%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.discount_kind'))->width('7%'),
                            TableColumn::make(__('sales.invoices.items.tax_rate'))->width('9%'),
                            TableColumn::make(__('sales.invoices.items.net'))->width('7%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.tax_amount'))->width('6%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.line_total'))->width('6%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('product_id')
                                ->options(fn (): array => self::purchasableProducts())
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(self::copyProductDefaults(...)),

                            TextInput::make('product_description'),

                            Select::make('expense_account_id')
                                ->options(fn (): array => self::expenseAccounts())
                                ->default(fn (): ?string => self::defaultExpenseAccount())
                                ->searchable()
                                ->required(),

                            TextInput::make('quantity')
                                ->numeric()
                                ->minValue(0)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true),

                            TextInput::make('unit_price')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->required()
                                ->live(onBlur: true),

                            Toggle::make('is_inclusive')
                                ->inline(false)
                                ->live(),

                            TextInput::make('discount_value')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true),

                            Select::make('discount_type')
                                ->options(DiscountType::class)
                                ->default(DiscountType::Percentage)
                                ->selectablePlaceholder(false)
                                ->live(),

                            Select::make('tax_id')
                                ->options(fn (): array => self::taxes())
                                ->default(fn (): ?string => Tax::query()->where('is_default', true)->value('id'))
                                ->live(),

                            TextInput::make('net_amount')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function (Get $get): string {
                                    $amounts = self::compute($get);

                                    return $amounts === null ? '0.0000' : $amounts->netAmount;
                                }),

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
                    Textarea::make('terms_and_conditions')
                        ->label(__('purchases.debit_notes.fields.terms_and_conditions')),

                    Textarea::make('notes')
                        ->label(__('purchases.debit_notes.fields.notes')),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }

    private static function copyProductDefaults(Set $set, Get $get, ?string $state): void
    {
        if (blank($state)) {
            return;
        }

        $product = Product::query()->find($state);

        if ($product === null) {
            return;
        }

        if (blank($get('product_description'))) {
            $set('product_description', $product->description);
        }

        if ($product->buying_price !== null) {
            $set('unit_price', (string) $product->buying_price);
        }

        if ($product->tax_id !== null) {
            $set('tax_id', $product->tax_id);
        }

        if ($product->expense_account_id !== null) {
            $set('expense_account_id', $product->expense_account_id);
        }
    }

    private static function compute(Get $get): ?LineAmounts
    {
        try {
            return app(InvoiceCalculator::class)->line(
                quantity: (string) ($get('quantity') ?? '0'),
                unitPrice: (string) ($get('unit_price') ?? '0'),
                isInclusive: (bool) $get('is_inclusive'),
                discountType: self::discountType($get('discount_type')),
                discountValue: (string) ($get('discount_value') ?? '0'),
                taxRate: self::rateOf($get('tax_id')),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function discountType(mixed $state): DiscountType
    {
        if ($state instanceof DiscountType) {
            return $state;
        }

        return is_string($state)
            ? DiscountType::tryFrom($state) ?? DiscountType::Percentage
            : DiscountType::Percentage;
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
                    quantity: (string) ($item['quantity'] ?? '0'),
                    unitPrice: (string) ($item['unit_price'] ?? '0'),
                    isInclusive: (bool) ($item['is_inclusive'] ?? false),
                    discountType: self::discountType($item['discount_type'] ?? null),
                    discountValue: (string) ($item['discount_value'] ?? '0'),
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
    private static function purchasableProducts(): array
    {
        return Product::query()
            ->purchasable()
            ->orderBy('sku')
            ->get()
            ->mapWithKeys(fn (Product $p): array => [
                $p->getKey() => $p->sku.' — '.$p->displayName(),
            ])
            ->all();
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

    private static function defaultExpenseAccount(): ?string
    {
        return Account::query()
            ->where('system_key', SystemAccount::CostOfGoodsSold->value)
            ->value('id');
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
