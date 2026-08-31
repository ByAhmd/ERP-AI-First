<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepreciationRuns\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The charges a run posted — one row per asset per period of record.
 */
class RunChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('assets.runs.charges.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['asset', 'period', 'postedPeriod']))
            ->columns([
                TextColumn::make('asset.reference')
                    ->label(__('assets.runs.charges.asset_reference')),

                TextColumn::make('asset.name')
                    ->label(__('assets.runs.charges.asset')),

                TextColumn::make('period.name')
                    ->label(__('assets.register.charges.period')),

                TextColumn::make('postedPeriod.name')
                    ->label(__('assets.register.charges.posted_period')),

                TextColumn::make('days')
                    ->label(__('assets.register.charges.days'))
                    ->alignEnd(),

                TextColumn::make('amount')
                    ->label(__('assets.register.charges.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold'),
            ])
            ->defaultSort('id')
            ->paginated([25, 50]);
    }
}
