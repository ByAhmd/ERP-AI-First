<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReceipts;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerReceipts\Pages\CreateCustomerReceipt;
use App\Filament\Resources\CustomerReceipts\Pages\EditCustomerReceipt;
use App\Filament\Resources\CustomerReceipts\Pages\ListCustomerReceipts;
use App\Filament\Resources\CustomerReceipts\Pages\ViewCustomerReceipt;
use App\Filament\Resources\CustomerReceipts\Schemas\CustomerReceiptForm;
use App\Models\CustomerReceipt;
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
 * Customer receipts — سندات العملاء.
 *
 * Same lifecycle discipline as every posting document: drafts are edited,
 * approved receipts are read. What changes after approval is the allocation of
 * the advance, and each such change is its own accounting event through the
 * poster — never an edit of the receipt.
 */
class CustomerReceiptResource extends Resource
{
    protected static ?string $model = CustomerReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 28;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('sales.receipts.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.receipts.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.receipts.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerReceiptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('sales.receipts.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('sales.receipts.columns.contact'))
                    ->searchable(),

                TextColumn::make('depositAccount.name')
                    ->label(__('sales.receipts.columns.account')),

                TextColumn::make('receipt_date')
                    ->label(__('sales.receipts.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label(__('sales.receipts.columns.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                // Qoyod's usage badge, derived — no stored balance.
                TextColumn::make('allocations_sum_amount')
                    ->label(__('sales.receipts.columns.allocated'))
                    ->sum('allocations', 'amount')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->placeholder('0.00'),

                TextColumn::make('status')
                    ->label(__('sales.receipts.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('receipt_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('sales.receipts.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (CustomerReceipt $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (CustomerReceipt $record): bool => ! $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCustomerReceipts::route('/'),
            'create' => CreateCustomerReceipt::route('/create'),
            'edit' => EditCustomerReceipt::route('/{record}/edit'),
            'view' => ViewCustomerReceipt::route('/{record}'),
        ];
    }
}
