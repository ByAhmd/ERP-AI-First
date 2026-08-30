<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Enums\DiscountType;
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
 * The purchase order form.
 *
 * Qoyod's أمر شراء field set: the order number, the supplier, the issue and
 * expiry dates, and the bill's line table without the account column — the
 * debit side is a property of the bill, resolved when the order converts.
 * Product picks copy the buying price, never the selling price.
 */
class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('purchases.orders.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('purchases.orders.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('contact_id')
                        ->label(__('purchases.orders.fields.contact'))
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

                    DatePicker::make('issue_date')
                        ->label(__('purchases.orders.fields.issue_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    DatePicker::make('expiry_date')
                        ->label(__('purchases.orders.fields.expiry_date'))
                        ->helperText(__('purchases.orders.hints.expiry_date'))
                        ->native(false)
                        ->default(now()->addDays(30))
                        ->required()
                        ->afterOrEqual('issue_date'),

                    TextInput::make('description')
                        ->label(__('purchases.orders.fields.description'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('purchases.orders.sections.items'))
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
                                ->options(fn (): array => self::purchasableProducts())
                                ->searchable()
                                ->live()
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

            Section::make(__('purchases.orders.sections.notes'))
                ->schema([
                    Textarea::make('terms_and_conditions')
                        ->label(__('purchases.orders.fields.terms_and_conditions')),

                    Textarea::make('notes')
                        ->label(__('purchases.orders.fields.notes')),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }

    /**
     * Copy a product's defaults onto the line — the BUYING price, never the
     * selling one, and never at all when the product carries none.
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

        if (blank($get('product_description'))) {
            $set('product_description', $product->description);
        }

        if ($product->buying_price !== null) {
            $set('unit_price', (string) $product->buying_price);
        }

        if ($product->tax_id !== null) {
            $set('tax_id', $product->tax_id);
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
    private static function taxes(): array
    {
        return Tax::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (Tax $t): array => [$t->getKey() => $t->displayName()])
            ->all();
    }
}
