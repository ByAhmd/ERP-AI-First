<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesCreditNotes\Schemas;

use App\Enums\CreditNoteReason;
use App\Enums\DiscountType;
use App\Enums\InvoiceSubtype;
use App\Enums\TaxCategory;
use App\Models\Contact;
use App\Models\Product;
use App\Models\SalesInvoice;
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
 * The credit note form.
 *
 * Qoyod's invoice form with its three differences: a select for the invoice
 * being credited, an always-required reference to the original (free text,
 * because the original may have been raised on paper before the company was on
 * any system), and no supply date. The line table is identical — the same
 * columns in the same order.
 *
 * Two fields Qoyod does not show are here because ZATCA requires them: the
 * reason for issue and the date of the triggering event. The fifteen-day window
 * runs from the end of the event's month, which is why the event date cannot be
 * inferred from the issue date.
 */
class SalesCreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('sales.invoices.sections.details'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('sales.credit_notes.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    Select::make('contact_id')
                        ->label(__('sales.credit_notes.fields.contact'))
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

                    Select::make('parent_id')
                        ->label(__('sales.credit_notes.fields.parent'))
                        // Only this customer's approved invoices: a draft has
                        // never reached the ledger, and the poster refuses a
                        // customer mismatch anyway — the form simply avoids
                        // offering what will be refused.
                        ->options(function (Get $get): array {
                            $contactId = $get('contact_id');

                            if (blank($contactId)) {
                                return [];
                            }

                            return SalesInvoice::query()
                                ->approved()
                                ->where('contact_id', $contactId)
                                ->orderByDesc('issue_date')
                                ->get()
                                ->mapWithKeys(fn (SalesInvoice $i): array => [
                                    $i->getKey() => $i->reference.' — '.number_format((float) $i->total, 2),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->live()
                        // Choosing an invoice fills the reference and date the
                        // note must carry. Snapshots, so they survive even if
                        // the link is later cleared.
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if (blank($state)) {
                                return;
                            }

                            $invoice = SalesInvoice::query()->find($state);

                            if ($invoice !== null) {
                                $set('original_invoice_number', $invoice->reference);
                                $set('original_invoice_date', $invoice->issue_date->toDateString());
                            }
                        }),

                    TextInput::make('original_invoice_number')
                        ->label(__('sales.credit_notes.fields.original_invoice_number'))
                        ->helperText(__('sales.credit_notes.hints.original_invoice_number'))
                        ->required()
                        ->maxLength(100),

                    DatePicker::make('original_invoice_date')
                        ->label(__('sales.credit_notes.fields.original_invoice_date'))
                        ->native(false),

                    DatePicker::make('issue_date')
                        ->label(__('sales.credit_notes.fields.issue_date'))
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                            if (filled($state) && blank($get('event_date'))) {
                                $set('event_date', $state);
                            }
                        }),

                    DatePicker::make('due_date')
                        ->label(__('sales.credit_notes.fields.due_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    DatePicker::make('event_date')
                        ->label(__('sales.credit_notes.fields.event_date'))
                        ->helperText(__('sales.credit_notes.hints.event_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    Select::make('subtype')
                        ->label(__('sales.invoices.fields.subtype'))
                        ->helperText(__('sales.invoices.hints.subtype'))
                        ->options(InvoiceSubtype::class)
                        ->default(InvoiceSubtype::Simplified)
                        ->selectablePlaceholder(false)
                        ->required()
                        // Only choosable for a note against an external
                        // original. Where a parent invoice exists, the model
                        // inherits its subtype whatever the form says, so a
                        // select would be a control that lies.
                        ->visible(fn (Get $get): bool => blank($get('parent_id'))),

                    Select::make('reason_code')
                        ->label(__('sales.credit_notes.fields.reason_code'))
                        ->options(CreditNoteReason::class)
                        ->required(),

                    Textarea::make('reason_text')
                        ->label(__('sales.credit_notes.fields.reason_text'))
                        ->helperText(__('sales.credit_notes.hints.reason_text'))
                        ->required()
                        ->rows(2)
                        ->columnSpan(2),

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

        if ($product->selling_price !== null) {
            $set('unit_price', (string) $product->selling_price);
        }

        if ($product->tax_id !== null) {
            $set('tax_id', $product->tax_id);
        }
    }

    /**
     * The display computation while typing.
     *
     * The stored figures come from CreditNoteRecalculator, which prefers the
     * rate snapshotted from the invoice line; the form cannot know that rate
     * until the line is saved, so what it shows is today's rate for the chosen
     * tax. For the ordinary case — crediting soon after invoicing — the two
     * agree; where they differ, the saved document is the authority.
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
