<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseDebitNotes;

use App\Enums\DocumentStatus;
use App\Filament\Resources\PurchaseDebitNotes\Pages\CreatePurchaseDebitNote;
use App\Filament\Resources\PurchaseDebitNotes\Pages\EditPurchaseDebitNote;
use App\Filament\Resources\PurchaseDebitNotes\Pages\ListPurchaseDebitNotes;
use App\Filament\Resources\PurchaseDebitNotes\Pages\ViewPurchaseDebitNote;
use App\Filament\Resources\PurchaseDebitNotes\Schemas\PurchaseDebitNoteForm;
use App\Models\PurchaseDebitNote;
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
 * Purchase debit notes — الإشعارات المدينة.
 *
 * The same lifecycle discipline as every posting document: drafts are
 * edited, approved notes are read. An approved note has reduced what a
 * supplier is owed — its own correction is a new document, not an edit.
 */
class PurchaseDebitNoteResource extends Resource
{
    protected static ?string $model = PurchaseDebitNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('purchases.debit_notes.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('purchases.debit_notes.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('purchases.debit_notes.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('purchases.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseDebitNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('purchases.debit_notes.fields.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('purchases.debit_notes.fields.contact'))
                    ->searchable(),

                // The supplier's invoice being corrected — what a reader
                // reconciling a supplier statement scans this list for.
                TextColumn::make('original_invoice_number')
                    ->label(__('purchases.debit_notes.fields.original_invoice_number'))
                    ->searchable(),

                TextColumn::make('issue_date')
                    ->label(__('purchases.debit_notes.fields.issue_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('subtotal_net')
                    ->label(__('purchases.invoices.columns.net'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('tax_total')
                    ->label(__('purchases.invoices.columns.tax'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('total')
                    ->label(__('purchases.invoices.columns.total'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('purchases.invoices.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('purchases.invoices.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (PurchaseDebitNote $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (PurchaseDebitNote $record): bool => ! $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseDebitNotes::route('/'),
            'create' => CreatePurchaseDebitNote::route('/create'),
            'edit' => EditPurchaseDebitNote::route('/{record}/edit'),
            'view' => ViewPurchaseDebitNote::route('/{record}'),
        ];
    }
}
