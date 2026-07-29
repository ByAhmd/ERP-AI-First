<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Tables;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FiscalYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('accounting.fiscal_years.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('start_date')
                    ->label(__('accounting.fiscal_years.columns.start'))
                    ->date()
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label(__('accounting.fiscal_years.columns.end'))
                    ->date(),

                TextColumn::make('periods_count')
                    ->label(__('accounting.fiscal_years.columns.periods'))
                    ->counts('periods'),

                TextColumn::make('status')
                    ->label(__('accounting.fiscal_years.columns.status'))
                    ->badge(),
            ])
            ->recordActions([
                Action::make('close')
                    ->label(__('accounting.fiscal_years.actions.close'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('warning')
                    ->visible(fn (FiscalYear $record): bool => $record->status === PeriodStatus::Open)
                    ->requiresConfirmation()
                    ->modalDescription(__('accounting.fiscal_years.actions.close_hint'))
                    ->action(function (FiscalYear $record): void {
                        $record->update([
                            'status' => PeriodStatus::Closed,
                            'closed_at' => now(),
                            'closed_by_id' => Filament::auth()->id(),
                        ]);

                        // Closing a year seals its periods. An open period
                        // beneath a closed year would be contradictory, and the
                        // posting gate checks both.
                        AccountingPeriod::query()
                            ->where('fiscal_year_id', $record->getKey())
                            ->update(['status' => PeriodStatus::Closed->value]);

                        Notification::make()
                            ->title(__('accounting.fiscal_years.notifications.closed'))
                            ->success()
                            ->send();
                    }),

                Action::make('reopen')
                    ->label(__('accounting.fiscal_years.actions.reopen'))
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->visible(fn (FiscalYear $record): bool => $record->status->canReopen())
                    ->requiresConfirmation()
                    ->modalDescription(__('accounting.fiscal_years.actions.reopen_hint'))
                    ->action(function (FiscalYear $record): void {
                        $record->update([
                            'status' => PeriodStatus::Open,
                            'closed_at' => null,
                            'closed_by_id' => null,
                        ]);

                        AccountingPeriod::query()
                            ->where('fiscal_year_id', $record->getKey())
                            ->update(['status' => PeriodStatus::Open->value]);

                        Notification::make()
                            ->title(__('accounting.fiscal_years.notifications.reopened'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('start_date', 'desc');
    }
}
