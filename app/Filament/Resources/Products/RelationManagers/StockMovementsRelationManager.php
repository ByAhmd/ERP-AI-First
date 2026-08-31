<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseInvoice;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A product's stock movements — Qoyod's تحركات screen.
 *
 * Read-only, always: this table is the append-only proof the stock ledger
 * writes, and no screen may add, edit or remove a row of it. Newest first
 * by application order, each row carrying the running balance it left
 * behind — the same figures Qoyod lists per product per location.
 */
class StockMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('inventory.stock.movements');
    }

    /**
     * Only a tracked product has movements to show.
     */
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['source', 'branch']))
            ->columns([
                TextColumn::make('movement_date')
                    ->label(__('inventory.stock.movement_date'))
                    ->date('d M Y'),

                TextColumn::make('source_type')
                    ->label(__('inventory.stock.operation'))
                    ->formatStateUsing(fn (string $state): string => self::operationLabel($state))
                    ->badge()
                    ->color(fn (StockMovement $record): string => bccomp((string) $record->quantity, '0', 4) >= 0
                        ? 'success'
                        : 'warning'),

                TextColumn::make('source.reference')
                    ->label(__('inventory.stock.movement_source'))
                    ->placeholder('—'),

                TextColumn::make('branch.name')
                    ->label(__('inventory.fields.branch')),

                TextColumn::make('quantity')
                    ->label(__('inventory.stock.movement_qty'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->color(fn (StockMovement $record): string => bccomp((string) $record->quantity, '0', 4) >= 0
                        ? 'success'
                        : 'danger'),

                TextColumn::make('unit_cost')
                    ->label(__('inventory.stock.movement_cost'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('value')
                    ->label(__('inventory.stock.movement_value'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('qty_after')
                    ->label(__('inventory.stock.movement_balance'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold'),
            ])
            // Application order, newest first — the running balance only
            // reads sensibly in the order the ledger applied it.
            ->defaultSort('id', 'desc')
            ->paginated([25, 50]);
    }

    /**
     * The operation each source class means — Qoyod's نوع العملية.
     */
    private static function operationLabel(string $sourceType): string
    {
        $key = match ($sourceType) {
            SalesInvoice::class => 'sale',
            PurchaseInvoice::class => 'purchase',
            SalesCreditNote::class => 'sales_return',
            PurchaseDebitNote::class => 'purchase_return',
            StockAdjustment::class => 'adjustment',
            InventoryTransfer::class => 'transfer',
            default => 'other',
        };

        return __("inventory.stock.operations.{$key}");
    }
}
