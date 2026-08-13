<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dimensions;

use App\Filament\Resources\Dimensions\Pages\CreateDimension;
use App\Filament\Resources\Dimensions\Pages\EditDimension;
use App\Filament\Resources\Dimensions\Pages\ListDimensions;
use App\Filament\Resources\Dimensions\RelationManagers\ValuesRelationManager;
use App\Filament\Resources\Dimensions\Schemas\DimensionForm;
use App\Filament\Resources\Dimensions\Tables\DimensionsTable;
use App\Models\Dimension;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * User-defined analytical dimensions.
 *
 * A dimension is defined here and its values managed underneath it, matching
 * Qoyod's arrangement where values are added from the dimension itself rather
 * than from a separate screen.
 */
class DimensionResource extends Resource
{
    protected static ?string $model = Dimension::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('accounting.dimensions.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('accounting.dimensions.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return DimensionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DimensionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDimensions::route('/'),
            'create' => CreateDimension::route('/create'),
            'edit' => EditDimension::route('/{record}/edit'),
        ];
    }
}
