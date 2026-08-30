<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\ContactStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The contact list, shared by customers and suppliers.
 *
 * Same columns in Qoyod's order on both screens; only the name column's
 * heading differs, so the caller supplies it.
 */
class ContactsTable
{
    public static function configure(Table $table, string $nameLabel): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('sales.contacts.columns.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact_name')
                    ->label($nameLabel)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('organization_name')
                    ->label(__('sales.contacts.columns.organization'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('primary_contact_number')
                    ->label(__('sales.contacts.columns.phone'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('primary_email')
                    ->label(__('sales.contacts.columns.email'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('tax_number')
                    ->label(__('sales.contacts.columns.tax_number'))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('sales.contacts.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('sales.contacts.columns.status'))
                    ->options(ContactStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
