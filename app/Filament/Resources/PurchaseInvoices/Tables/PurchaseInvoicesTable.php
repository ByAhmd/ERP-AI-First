<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInvoices\Tables;

use App\Enums\DocumentStatus;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\BillOutstanding;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The purchase invoices list.
 *
 * Decorated by BillOutstanding on every query: the payment badge reads
 * derived attributes, and without the decoration every settled bill would
 * render as unpaid.
 */
class PurchaseInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Attaches amount_paid and amount_debited; without this every
            // settled bill would render as unpaid, silently.
            ->modifyQueryUsing(fn ($query) => app(BillOutstanding::class)->decorate($query))
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

                TextColumn::make('payment_status')
                    ->label(__('purchases.invoices.columns.payment'))
                    ->state(fn (PurchaseInvoice $record): string => $record->isApproved()
                        ? __('purchases.payment_status.'.$record->paymentStatus())
                        : '—')
                    ->badge()
                    ->color(fn (PurchaseInvoice $record): string => $record->isApproved()
                        ? match ($record->paymentStatus()) {
                            'paid' => 'success',
                            'partially_paid' => 'warning',
                            default => 'gray',
                        }
                        : 'gray'),

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
