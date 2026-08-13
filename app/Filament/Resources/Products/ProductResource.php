<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products;

use App\Enums\ProductType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitType;
use App\Models\Tax;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Products, services and costs.
 *
 * Qoyod's own field set, in its order. Its wizard asks for the type first and
 * then the details; this asks for both on one screen, because Filament renders
 * the whole form at once and a two-step wizard for eight fields would be
 * ceremony rather than help. The fields, their labels and which of them are
 * required are unchanged.
 *
 * Prices appear only when the matching box is ticked, as they do in Qoyod —
 * `يُباع` reveals the selling price, `يُشترى` the buying price.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('sales.products.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.products.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.products.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('sales.products.sections.details'))
                ->schema([
                    Select::make('type')
                        ->label(__('sales.products.fields.type'))
                        ->options(ProductType::class)
                        ->default(ProductType::Product)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('sku')
                        ->label(__('sales.products.fields.sku'))
                        ->helperText(__('sales.products.hints.sku'))
                        ->maxLength(60),

                    TextInput::make('name')
                        ->label(__('sales.products.fields.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('sales.products.fields.name_en'))
                        ->helperText(__('sales.products.hints.name_en'))
                        ->required()
                        ->maxLength(255),

                    Select::make('category_id')
                        ->label(__('sales.products.fields.category'))
                        ->options(fn (): array => ProductCategory::query()
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?string => ProductCategory::query()
                            ->where('is_default', true)->value('id'))
                        ->searchable()
                        ->required(),

                    Select::make('unit_type_id')
                        ->label(__('sales.products.fields.unit_type'))
                        ->options(fn (): array => ProductUnitType::query()
                            ->where('is_active', true)->pluck('name', 'id')->all())
                        ->required(),

                    Select::make('tax_id')
                        ->label(__('sales.products.fields.tax'))
                        ->options(fn (): array => Tax::query()
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (Tax $tax): array => [
                                $tax->getKey() => $tax->displayName(),
                            ])
                            ->all())
                        ->default(fn (): ?string => Tax::query()
                            ->where('is_default', true)->value('id'))
                        ->required(),

                    TextInput::make('barcode')
                        ->label(__('sales.products.fields.barcode'))
                        ->maxLength(60),

                    Textarea::make('description')
                        ->label(__('sales.products.fields.description'))
                        ->columnSpanFull(),

                    Textarea::make('terms_and_conditions')
                        ->label(__('sales.products.fields.terms_and_conditions'))
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label(__('sales.products.fields.is_active'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('sales.products.sections.pricing'))
                ->schema([
                    Toggle::make('is_sold')
                        ->label(__('sales.products.fields.is_sold'))
                        ->default(true)
                        ->live(),

                    Toggle::make('is_purchased')
                        ->label(__('sales.products.fields.is_purchased'))
                        ->live(),

                    TextInput::make('selling_price')
                        ->label(__('sales.products.fields.selling_price'))
                        ->helperText(__('sales.products.hints.selling_price'))
                        ->numeric()
                        ->minValue(0)
                        // Revealed by the tick, as in Qoyod.
                        ->visible(fn (Get $get): bool => (bool) $get('is_sold'))
                        ->required(fn (Get $get): bool => (bool) $get('is_sold')),

                    TextInput::make('buying_price')
                        ->label(__('sales.products.fields.buying_price'))
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => (bool) $get('is_purchased'))
                        ->required(fn (Get $get): bool => (bool) $get('is_purchased')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label(__('sales.products.columns.sku'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('sales.products.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('sales.products.columns.type'))
                    ->badge(),

                TextColumn::make('category.name')
                    ->label(__('sales.products.columns.category'))
                    ->placeholder('—'),

                TextColumn::make('unitType.name')
                    ->label(__('sales.products.columns.unit'))
                    ->placeholder('—'),

                TextColumn::make('tax.name')
                    ->label(__('sales.products.columns.tax'))
                    ->placeholder('—'),

                TextColumn::make('selling_price')
                    ->label(__('sales.products.columns.selling_price'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->placeholder('—')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('sales.products.columns.active'))
                    ->boolean(),
            ])
            ->defaultSort('sku')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('sales.products.columns.type'))
                    ->options(ProductType::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
