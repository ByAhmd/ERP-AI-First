<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets;

use App\Enums\FixedAssetStatus;
use App\Filament\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Resources\FixedAssets\Pages\ViewFixedAsset;
use App\Filament\Resources\FixedAssets\Schemas\FixedAssetForm;
use App\Models\FixedAsset;
use App\Models\FixedAssetType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The asset register — الأصول الثابتة.
 *
 * List columns follow Qoyod's export sheet, the best confirmed proxy for
 * their register screen: reference, name, type, dates, cost, accumulated to
 * date and book value. Accumulated is the opening figure plus posted
 * charges, summed by the database rather than stored anywhere.
 */
class FixedAssetResource extends Resource
{
    protected static ?string $model = FixedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('assets.register.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assets.register.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('assets.register.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('assets.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return FixedAssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['type', 'branch'])
                ->withSum('charges', 'amount'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('assets.register.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('assets.register.columns.name'))
                    ->searchable()
                    ->description(fn (FixedAsset $record): ?string => $record->name_en),

                TextColumn::make('type.name')
                    ->label(__('assets.register.columns.type')),

                TextColumn::make('branch.name')
                    ->label(__('assets.register.columns.branch'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('in_service_date')
                    ->label(__('assets.register.columns.in_service_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('cost')
                    ->label(__('assets.register.columns.cost'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('accumulated')
                    ->label(__('assets.register.columns.accumulated'))
                    ->state(fn (FixedAsset $record): string => number_format(
                        (float) bcadd(
                            (string) $record->opening_accumulated_depreciation,
                            (string) ($record->getAttribute('charges_sum_amount') ?? '0'),
                            4,
                        ),
                        2,
                    ))
                    ->alignEnd(),

                TextColumn::make('book_value')
                    ->label(__('assets.register.columns.book_value'))
                    ->state(fn (FixedAsset $record): string => number_format(
                        (float) bcsub(
                            (string) $record->cost,
                            bcadd(
                                (string) $record->opening_accumulated_depreciation,
                                (string) ($record->getAttribute('charges_sum_amount') ?? '0'),
                                4,
                            ),
                            4,
                        ),
                        2,
                    ))
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label(__('assets.register.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('reference')
            ->filters([
                SelectFilter::make('fixed_asset_type_id')
                    ->label(__('assets.register.columns.type'))
                    ->options(fn (): array => FixedAssetType::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                SelectFilter::make('status')
                    ->label(__('assets.register.columns.status'))
                    ->options(FixedAssetStatus::class)
                    ->default(FixedAssetStatus::Active->value),
            ])
            ->recordActions([
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (FixedAsset $record): bool => $record->isActive()),
            ]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\DepreciationChargesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssets::route('/'),
            'create' => CreateFixedAsset::route('/create'),
            'view' => ViewFixedAsset::route('/{record}'),
            'edit' => EditFixedAsset::route('/{record}/edit'),
        ];
    }
}
