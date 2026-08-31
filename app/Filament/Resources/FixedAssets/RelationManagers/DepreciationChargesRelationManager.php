<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The asset's posted charges — the subledger rows, read-only always.
 *
 * Each row names both its period of record and the period the money landed
 * in; the two differ exactly when a catch-up crossed a closed period, and
 * showing both is the honesty the pair of columns exists for.
 */
class DepreciationChargesRelationManager extends RelationManager
{
    protected static string $relationship = 'charges';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('assets.register.charges.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['period', 'postedPeriod', 'run', 'journalEntry']))
            ->columns([
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

                TextColumn::make('run.reference')
                    ->label(__('assets.register.charges.run')),

                TextColumn::make('journalEntry.number')
                    ->label(__('assets.register.charges.entry'))
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50]);
    }
}
