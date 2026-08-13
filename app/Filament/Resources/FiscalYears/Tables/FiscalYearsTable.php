<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Tables;

use App\Enums\PeriodStatus;
use App\Models\FiscalYear;
use App\Services\Accounting\Exceptions\PeriodTransitionRejected;
use App\Services\Accounting\FiscalYearCloser;
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
                    ->action(function (FiscalYear $record, FiscalYearCloser $closer): void {
                        try {
                            $closer->close($record, Filament::auth()->id());
                        } catch (PeriodTransitionRejected $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

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
                    ->action(function (FiscalYear $record, FiscalYearCloser $closer): void {
                        try {
                            $closer->reopen($record, Filament::auth()->id());
                        } catch (PeriodTransitionRejected $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

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
