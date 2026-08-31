<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockAdjustments;

use App\Enums\DocumentStatus;
use App\Enums\StockAdjustmentKind;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\Schemas\StockAdjustmentForm;
use App\Models\StockAdjustment;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Stock adjustments — تسويات المخزون.
 *
 * Opening balances and count variances, with the posting lifecycle every
 * document here carries: drafts are edited, approved adjustments are
 * immutable, correction is a counter-adjustment.
 */
class StockAdjustmentResource extends Resource
{
    protected static ?string $model = StockAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('inventory.adjustments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory.adjustments.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('inventory.adjustments.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.products_group');
    }

    public static function form(Schema $schema): Schema
    {
        return StockAdjustmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('inventory.adjustments.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->label(__('inventory.adjustments.columns.kind'))
                    ->badge(),

                TextColumn::make('branch.name')
                    ->label(__('inventory.adjustments.columns.branch')),

                TextColumn::make('adjustment_date')
                    ->label(__('inventory.adjustments.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('inventory.adjustments.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('adjustment_date', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('inventory.adjustments.columns.kind'))
                    ->options(StockAdjustmentKind::class),

                SelectFilter::make('status')
                    ->label(__('inventory.adjustments.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (StockAdjustment $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (StockAdjustment $record): bool => ! $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStockAdjustments::route('/'),
            'create' => CreateStockAdjustment::route('/create'),
            'edit' => EditStockAdjustment::route('/{record}/edit'),
            'view' => ViewStockAdjustment::route('/{record}'),
        ];
    }
}
