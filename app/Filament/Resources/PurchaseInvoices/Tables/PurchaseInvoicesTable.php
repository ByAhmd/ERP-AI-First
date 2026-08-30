<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInvoices\Tables;

use App\Enums\DocumentStatus;
use App\Models\PurchaseInvoice;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The purchase invoices list.
 *
 * The payment column arrives with the payments slice, together with the
 * BillOutstanding decoration that makes it truthful — a badge without the
 * decoration would show every settled bill as unpaid.
 */
class PurchaseInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('purchases.invoices.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('purchases.invoices.columns.contact'))
                    ->searchable(),

                // What the supplier calls this bill — the number a clerk has
                // in hand when they come looking.
                TextColumn::make('supplier_invoice_number')
                    ->label(__('purchases.invoices.columns.supplier_invoice_number'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('issue_date')
                    ->label(__('purchases.invoices.columns.issue_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label(__('purchases.invoices.columns.due_date'))
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('—'),

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
                    ->visible(fn (PurchaseInvoice $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (PurchaseInvoice $record): bool => ! $record->isDraft()),
            ]);
    }
}
