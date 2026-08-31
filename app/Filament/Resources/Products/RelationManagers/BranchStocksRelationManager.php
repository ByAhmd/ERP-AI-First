<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A product's quantity at each branch — Qoyod's per-location figures.
 *
 * Read-only: these rows are the stock ledger's state, written only under
 * its lock. The value column is quantity times the company average, stated
 * live rather than stored — cost is company-wide by design.
 */
class BranchStocksRelationManager extends RelationManager
{
    protected static string $relationship = 'stocks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('inventory.stock.per_branch');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product && $ownerRecord->track_inventory;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->columns([
                TextColumn::make('branch.name')
                    ->label(__('inventory.fields.branch')),

                TextColumn::make('quantity_on_hand')
                    ->label(__('inventory.stock.quantity'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('value')
                    ->label(__('inventory.stock.total_value'))
                    ->state(function ($record): string {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        $cost = $product->costRecord;
                        $average = $cost === null ? '0' : (string) $cost->average_cost;

                        return number_format(
                            (float) bcmul((string) $record->quantity_on_hand, $average, 4),
                            2,
                        );
                    })
                    ->alignEnd(),
            ])
            ->defaultSort('quantity_on_hand', 'desc')
            ->paginated(false);
    }
}
