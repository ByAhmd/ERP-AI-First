<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesInvoices\Tables;

use App\Enums\DocumentStatus;
use App\Models\SalesInvoice;
use App\Services\Sales\InvoiceOutstanding;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Two correlated subqueries, once per query — not a sum per row.
            ->modifyQueryUsing(fn ($query) => app(InvoiceOutstanding::class)->decorate($query))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('sales.invoices.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.contact_name')
                    ->label(__('sales.invoices.columns.contact'))
                    ->searchable(),

                TextColumn::make('issue_date')
                    ->label(__('sales.invoices.columns.issue_date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label(__('sales.invoices.columns.due_date'))
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

                TextColumn::make('subtype')
                    ->label(__('sales.invoices.fields.subtype'))
                    ->badge(),

                TextColumn::make('payment_status')
                    ->label(__('sales.invoices.columns.payment'))
                    ->state(fn (SalesInvoice $record): string => $record->isApproved()
                        ? __('sales.payment_status.'.$record->paymentStatus())
                        : '—')
                    ->badge()
                    ->color(fn (SalesInvoice $record): string => $record->isApproved()
                        ? match ($record->paymentStatus()) {
                            'paid' => 'success',
                            'partially_paid' => 'warning',
                            default => 'gray',
                        }
                        : 'gray'),

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
                // A draft is edited; an approved invoice is only read. Offering
                // an edit action that the service is bound to refuse would be a
                // worse way to say so.
                EditAction::make()
                        ->visible(fn (SalesInvoice $record): bool => $record->isDraft()),

                ViewAction::make()
                        ->visible(fn (SalesInvoice $record): bool => ! $record->isDraft()),
            ]);
    }
}
