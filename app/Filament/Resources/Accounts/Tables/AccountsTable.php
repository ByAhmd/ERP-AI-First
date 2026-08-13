<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Tables;

use App\Enums\AccountType;
use App\Models\Account;
use App\Services\Accounting\Exceptions\AccountStructureViolation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('accounting.accounts.columns.code'))
                    ->searchable()
                    ->sortable()
                    // Indented by depth so the hierarchy is legible without a
                    // dedicated tree widget.
                    ->formatStateUsing(fn (Account $record): string => str_repeat('　', $record->depth).$record->code)
                    ->weight(fn (Account $record): string => $record->is_postable ? 'normal' : 'bold'),

                TextColumn::make('name')
                    ->label(__('accounting.accounts.columns.name'))
                    ->searchable()
                    ->description(fn (Account $record): ?string => $record->name_en),

                TextColumn::make('type')
                    ->label(__('accounting.accounts.columns.type'))
                    ->badge(),

                IconColumn::make('is_postable')
                    ->label(__('accounting.accounts.columns.postable'))
                    ->boolean()
                    ->tooltip(fn (Account $record): string => $record->is_postable
                        ? __('accounting.accounts.hints.postable')
                        : __('accounting.accounts.hints.group')),

                IconColumn::make('is_system')
                    ->label(__('accounting.accounts.columns.system'))
                    ->boolean()
                    ->tooltip(__('accounting.accounts.hints.system'))
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label(__('accounting.accounts.columns.active'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('accounting.accounts.columns.type'))
                    ->options(fn (): array => collect(AccountType::cases())
                        ->mapWithKeys(fn (AccountType $case): array => [$case->value => $case->getLabel()])
                        ->all()),

                TernaryFilter::make('is_postable')
                    ->label(__('accounting.accounts.columns.postable')),

                TernaryFilter::make('is_active')
                    ->label(__('accounting.accounts.columns.active'))
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    // Refused for system accounts and anything with history;
                    // hiding the action explains why before it is attempted.
                    ->visible(fn (Account $record): bool => ! $record->is_system)
                    ->action(function (Account $record): void {
                        try {
                            $record->delete();
                        } catch (AccountStructureViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('accounting.accounts.notifications.deleted'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            // Tree order. The materialised path sorts parents immediately
            // before their children at every level.
            ->defaultSort('path')
            ->paginated([50, 100, 'all'])
            ->defaultPaginationPageOption(50);
    }
}
