<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetTypes;

use App\Filament\Resources\FixedAssetTypes\Pages\CreateFixedAssetType;
use App\Filament\Resources\FixedAssetTypes\Pages\EditFixedAssetType;
use App\Filament\Resources\FixedAssetTypes\Pages\ListFixedAssetTypes;
use App\Filament\Resources\FixedAssetTypes\Schemas\FixedAssetTypeForm;
use App\Models\FixedAssetType;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asset classifications — تصنيفات الأصول.
 *
 * Qoyod buries these inside the assets screen; a sidebar item is the house
 * pattern (the product-categories precedent). Each type carries the three
 * accounts its assets post through.
 */
class FixedAssetTypeResource extends Resource
{
    protected static ?string $model = FixedAssetType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('assets.types.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assets.types.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('assets.types.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('assets.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return FixedAssetTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('assets'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('assets.types.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (FixedAssetType $record): ?string => $record->name_en),

                TextColumn::make('assetAccount.name')
                    ->label(__('assets.types.columns.asset_account')),

                TextColumn::make('default_useful_life_months')
                    ->label(__('assets.types.columns.life'))
                    ->placeholder('—')
                    ->alignEnd(),

                IconColumn::make('is_depreciable')
                    ->label(__('assets.types.columns.depreciable'))
                    ->boolean(),

                TextColumn::make('assets_count')
                    ->label(__('assets.types.columns.assets_count'))
                    ->alignEnd(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    ->visible(fn (FixedAssetType $record): bool => ! $record->assets()->exists()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssetTypes::route('/'),
            'create' => CreateFixedAssetType::route('/create'),
            'edit' => EditFixedAssetType::route('/{record}/edit'),
        ];
    }
}
