<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesCreditNotes;

use App\Enums\DocumentStatus;
use App\Filament\Resources\SalesCreditNotes\Pages\CreateSalesCreditNote;
use App\Filament\Resources\SalesCreditNotes\Pages\EditSalesCreditNote;
use App\Filament\Resources\SalesCreditNotes\Pages\ListSalesCreditNotes;
use App\Filament\Resources\SalesCreditNotes\Pages\ViewSalesCreditNote;
use App\Filament\Resources\SalesCreditNotes\Schemas\SalesCreditNoteForm;
use App\Models\SalesCreditNote;
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
 * Credit notes.
 *
 * The same lifecycle discipline as the invoice: drafts are edited, approved
 * notes are read. An approved credit note has reached the ledger and reduced
 * what a customer owes — its own correction is a new document, not an edit.
 */
class SalesCreditNoteResource extends Resource
{
    protected static ?string $model = SalesCreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 27;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('sales.credit_notes.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.credit_notes.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.credit_notes.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sales.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return SalesCreditNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('sales.credit_notes.fields.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('sales.credit_notes.fields.contact'))
                    ->searchable(),

                // The invoice being corrected is what a reader scans this list
                // for, so it is a column rather than detail behind a click.
                TextColumn::make('original_invoice_number')
                    ->label(__('sales.credit_notes.fields.original_invoice_number'))
                    ->searchable(),

                TextColumn::make('issue_date')
                    ->label(__('sales.credit_notes.fields.issue_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('subtotal_net')
                    ->label(__('sales.invoices.columns.net'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('tax_total')
                    ->label(__('sales.invoices.columns.tax'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('total')
                    ->label(__('sales.invoices.columns.total'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('sales.invoices.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('sales.invoices.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (SalesCreditNote $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (SalesCreditNote $record): bool => ! $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSalesCreditNotes::route('/'),
            'create' => CreateSalesCreditNote::route('/create'),
            'edit' => EditSalesCreditNote::route('/{record}/edit'),
            'view' => ViewSalesCreditNote::route('/{record}'),
        ];
    }
}
