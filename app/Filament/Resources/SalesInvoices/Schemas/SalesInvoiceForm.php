<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesInvoices\Schemas;

use App\Enums\DiscountType;
use App\Enums\InvoiceSubtype;
use App\Enums\TaxCategory;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Tax;
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
 * The sales invoice form.
 *
 * Laid out as Qoyod lays it out: the header first, then the lines as a table
 * with its eleven columns in its order — المنتج, الوصف, الكمية, سعر الوحدة,
 * شامل؟, الخصم, الاجمالي قبل الضريبة, الضريبة %, قيمة الضريبة, القيمة. A
 * repeater table rather than a stack of cards, because an invoice is read
 * across its columns and a clerk entering twenty lines needs them aligned.
 *
 * The three rightmost columns are computed, not typed. They use the same
 * {@see InvoiceCalculator} the service uses when the invoice is saved, so what
 * a user watches while typing is what is ultimately stored — a second
 * implementation for display is how the two drift apart.
 */
class SalesInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('sales.invoices.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('sales.invoices.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('contact_id')
                        ->label(__('sales.invoices.fields.contact'))
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
                        ->live()
                        // Choosing a customer chooses the default document: a
                        // VAT-registered buyer needs a standard tax invoice, a
                        // consumer gets a simplified one. Still overridable.
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $contact = blank($state) ? null : Contact::query()->find($state);

                            $set('subtype', InvoiceSubtype::forContact($contact)->value);
                        }),

                    Select::make('subtype')
                        ->label(__('sales.invoices.fields.subtype'))
                        ->helperText(__('sales.invoices.hints.subtype'))
                        ->options(InvoiceSubtype::class)
                        ->default(InvoiceSubtype::Simplified)
                        ->selectablePlaceholder(false)
                        ->required(),

                    DatePicker::make('issue_date')
                        ->label(__('sales.invoices.fields.issue_date'))
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->live(onBlur: true)
                        // Qoyod defaults both to the issue date. The supply
                        // date genuinely differs when an invoice is raised
                        // after delivery, so it stays editable.
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if (filled($state)) {
                                $set('supply_date', $state);
                            }
                        }),

                    DatePicker::make('due_date')
                        ->label(__('sales.invoices.fields.due_date'))
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->afterOrEqual('issue_date'),

                    DatePicker::make('supply_date')
                        ->label(__('sales.invoices.fields.supply_date'))
                        ->helperText(__('sales.invoices.hints.supply_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('description')
                        ->label(__('sales.invoices.fields.description'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('sales.invoices.sections.items'))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('sales.invoices.items.product'))->width('18%'),
                            TableColumn::make(__('sales.invoices.items.description'))->width('16%'),
                            TableColumn::make(__('sales.invoices.items.quantity'))->width('7%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.unit_price'))->width('10%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.is_inclusive'))->width('6%')->alignCenter(),
                            TableColumn::make(__('sales.invoices.items.discount'))->width('7%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.discount_kind'))->width('8%'),
                            TableColumn::make(__('sales.invoices.items.tax_rate'))->width('11%'),
                            TableColumn::make(__('sales.invoices.items.net'))->width('8%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.tax_amount'))->width('7%')->alignEnd(),
                            TableColumn::make(__('sales.invoices.items.line_total'))->width('7%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('product_id')
                                ->options(fn (): array => self::sellableProducts())
                                ->searchable()
                                ->live()
                                // Choosing a product copies its defaults onto
                                // the line. Copies, not references: the line
                                // must survive the product being re-priced.
                                ->afterStateUpdated(self::copyProductDefaults(...)),

                            TextInput::make('product_description'),

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

                            // Computed. Shown as disabled inputs rather than
                            // placeholders so the table's columns line up and
                            // the figures sit where Qoyod puts them.
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
                        // The running total is what a clerk watches. Computed
                        // by the same calculator that will store it.
                        ->helperText(fn (Get $get): string => self::totalsSummary($get('items') ?? [])),
                ]),

            Section::make(__('sales.invoices.sections.notes'))
                ->schema([
                    Textarea::make('terms_and_conditions')
                        ->label(__('sales.invoices.fields.terms_and_conditions')),

                    Textarea::make('notes')
                        ->label(__('sales.invoices.fields.notes')),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }

    /**
     * Copy a product's defaults onto the line it was chosen for.
     */
    private static function copyProductDefaults(Set $set, Get $get, ?string $state): void
    {
        if (blank($state)) {
            return;
        }

        $product = Product::query()->find($state);

        if ($product === null) {
            return;
        }

        // The name and unit are snapshotted by the model on create, so only
        // the things a user may then override are set here.
        if (blank($get('product_description'))) {
            $set('product_description', $product->description);
        }

        if ($product->selling_price !== null) {
            $set('unit_price', (string) $product->selling_price);
        }

        if ($product->tax_id !== null) {
            $set('tax_id', $product->tax_id);
        }
    }

    /**
     * Resolve one row's figures from what is currently typed into it.
     *
     * Returns null rather than throwing when the row is half-filled or the
     * discount is nonsensical — a form mid-edit is not an error, and the
     * service will refuse it properly at save.
     */
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

    /**
     * Form state hands back an enum once a default is applied and a string
     * while the select is being changed — the trap that returned a 500 from
     * the tax form on every visit.
     */
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
    private static function sellableProducts(): array
    {
        return Product::query()
            ->sellable()
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
    private static function taxes(): array
    {
        return Tax::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (Tax $t): array => [$t->getKey() => $t->displayName()])
            ->all();
    }
}
