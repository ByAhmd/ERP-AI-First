<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryTransfers;

use App\Enums\DocumentStatus;
use App\Filament\Resources\InventoryTransfers\Pages\CreateInventoryTransfer;
use App\Filament\Resources\InventoryTransfers\Pages\EditInventoryTransfer;
use App\Filament\Resources\InventoryTransfers\Pages\ListInventoryTransfers;
use App\Filament\Resources\InventoryTransfers\Pages\ViewInventoryTransfer;
use App\Filament\Resources\InventoryTransfers\Schemas\InventoryTransferForm;
use App\Models\InventoryTransfer;
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
 * Inventory transfers — نقل المخزون.
 *
 * Drafts are edited; an approved transfer moved real goods between real
 * shelves, and its correction is a transfer back — never an edit.
 */
class InventoryTransferResource extends Resource
{
    protected static ?string $model = InventoryTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('inventory.transfers.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory.transfers.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('inventory.transfers.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.products_group');
    }

    public static function form(Schema $schema): Schema
    {
        return InventoryTransferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('inventory.transfers.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fromBranch.name')
                    ->label(__('inventory.transfers.columns.from_branch')),

                TextColumn::make('toBranch.name')
                    ->label(__('inventory.transfers.columns.to_branch')),

                TextColumn::make('transfer_date')
                    ->label(__('inventory.transfers.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('inventory.transfers.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('transfer_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('inventory.transfers.columns.status'))
                    ->options(DocumentStatus::class),

                SelectFilter::make('from_branch_id')
                    ->label(__('inventory.transfers.columns.from_branch'))
                    ->relationship('fromBranch', 'name'),

                SelectFilter::make('to_branch_id')
                    ->label(__('inventory.transfers.columns.to_branch'))
                    ->relationship('toBranch', 'name'),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (InventoryTransfer $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (InventoryTransfer $record): bool => ! $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryTransfers::route('/'),
            'create' => CreateInventoryTransfer::route('/create'),
            'edit' => EditInventoryTransfer::route('/{record}/edit'),
            'view' => ViewInventoryTransfer::route('/{record}'),
        ];
    }
}
