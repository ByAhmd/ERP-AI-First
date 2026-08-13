<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories;

use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Models\ProductCategory;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * How a company groups what it sells.
 *
 * Three fields, which is all Qoyod's category screen has: a name, a
 * description and a parent. No accounts — revenue posts to a company-level
 * default rather than being derived here, and a category that looked like it
 * carried accounting would be the first place someone went looking for it.
 *
 * Managed in a modal rather than on its own pages: a category is created in
 * passing while adding a product, and a full page for three fields interrupts
 * that.
 */
class ProductCategoryResource extends Resource
{
    protected static ?string $model = ProductCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('sales.product_categories.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.product_categories.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.product_categories.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('sales.product_categories.fields.name'))
                ->required()
                ->maxLength(255),

            Textarea::make('description')
                ->label(__('sales.product_categories.fields.description')),

            Select::make('parent_id')
                ->label(__('sales.product_categories.fields.parent'))
                ->options(fn (?ProductCategory $record): array => ProductCategory::query()
                    // A category cannot be its own parent.
                    ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('sales.product_categories.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('parent.name')
                    ->label(__('sales.product_categories.columns.parent'))
                    ->placeholder('—'),

                TextColumn::make('description')
                    ->label(__('sales.product_categories.columns.description'))
                    ->placeholder('—')
                    ->limit(60),
            ])
            ->defaultSort('name')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                // The default category is what every product falls back to.
                DeleteAction::make()
                    ->hidden(fn (ProductCategory $record): bool => $record->is_default),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProductCategories::route('/'),
        ];
    }
}
