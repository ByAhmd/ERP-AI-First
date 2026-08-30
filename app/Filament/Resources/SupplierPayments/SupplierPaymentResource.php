<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments;

use App\Enums\DocumentStatus;
use App\Filament\Resources\SupplierPayments\Pages\CreateSupplierPayment;
use App\Filament\Resources\SupplierPayments\Pages\EditSupplierPayment;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Filament\Resources\SupplierPayments\Pages\ViewSupplierPayment;
use App\Filament\Resources\SupplierPayments\Schemas\SupplierPaymentForm;
use App\Models\SupplierPayment;
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
 * Supplier payment vouchers — سندات الموردين.
 *
 * The same lifecycle discipline as the receipt: drafts are edited, approved
 * vouchers are read. What changes after approval is the allocation of the
 * advance, and each such change is its own accounting event through the
 * poster — never an edit of the voucher.
 */
class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('purchases.payments.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases.payments.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('purchases.payments.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('purchases.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierPaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('purchases.payments.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('purchases.payments.columns.contact'))
                    ->searchable(),

                TextColumn::make('paymentAccount.name')
                    ->label(__('purchases.payments.columns.account')),

                TextColumn::make('payment_date')
                    ->label(__('purchases.payments.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label(__('purchases.payments.columns.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                // Qoyod's usage figure, derived — no stored balance.
                TextColumn::make('allocations_sum_amount')
                    ->label(__('purchases.payments.columns.allocated'))
                    ->sum('allocations', 'amount')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->placeholder('0.00'),

                TextColumn::make('status')
                    ->label(__('purchases.payments.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('purchases.payments.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (SupplierPayment $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (SupplierPayment $record): bool => ! $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSupplierPayments::route('/'),
            'create' => CreateSupplierPayment::route('/create'),
            'edit' => EditSupplierPayment::route('/{record}/edit'),
            'view' => ViewSupplierPayment::route('/{record}'),
        ];
    }
}
