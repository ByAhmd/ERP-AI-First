<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Tables;

use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Accounting\JournalPoster;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('accounting.entries.columns.number'))
                    ->searchable()
                    ->sortable()
                    // Draft placeholders are internal bookkeeping, not something
                    // to show a user.
                    ->formatStateUsing(fn (JournalEntry $record): string => $record->isDraft()
                        ? '—'
                        : $record->number),

                TextColumn::make('entry_date')
                    ->label(__('accounting.entries.columns.date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('accounting.entries.columns.description'))
                    ->searchable()
                    ->limit(50)
                    ->description(fn (JournalEntry $record): ?string => $record->reference),

                TextColumn::make('total_debit')
                    ->label(__('accounting.entries.columns.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('accounting.entries.columns.status'))
                    ->badge(),

                TextColumn::make('reverses.number')
                    ->label(__('accounting.entries.columns.reverses'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('postedBy.name')
                    ->label(__('accounting.entries.columns.posted_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('accounting.entries.columns.status'))
                    ->options(fn (): array => collect(JournalEntryStatus::cases())
                        ->mapWithKeys(fn (JournalEntryStatus $case): array => [
                            $case->value => $case->getLabel(),
                        ])
                        ->all()),

                Filter::make('entry_date')
                    ->schema([
                        DatePicker::make('from')->label(__('accounting.entries.filters.from'))->native(false),
                        DatePicker::make('until')->label(__('accounting.entries.filters.until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('entry_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('entry_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('post')
                    ->label(__('accounting.entries.actions.post'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft())
                    ->requiresConfirmation()
                    ->modalDescription(__('accounting.entries.actions.post_hint'))
                    ->action(function (JournalEntry $record, JournalPoster $poster): void {
                        try {
                            $posted = $poster->postDraft($record, Filament::auth()->id());
                        } catch (PostingRejected $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('accounting.entries.notifications.posted', ['number' => $posted->number]))
                            ->success()
                            ->send();
                    }),

                Action::make('reverse')
                    ->label(__('accounting.entries.actions.reverse'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (JournalEntry $record): bool => $record->isPosted()
                        && ! $record->reversedBy()->exists())
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('accounting.entries.actions.reversal_date'))
                            ->native(false)
                            ->default(now())
                            ->required()
                            ->helperText(__('accounting.entries.actions.reversal_date_hint')),
                    ])
                    ->action(function (JournalEntry $record, array $data, JournalPoster $poster): void {
                        try {
                            $reversal = $poster->reverse(
                                original: $record,
                                date: CarbonImmutable::parse($data['date']),
                                userId: Filament::auth()->id(),
                            );
                        } catch (PostingRejected $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('accounting.entries.notifications.reversed', ['number' => $reversal->number]))
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([])
            ->defaultSort('entry_date', 'desc');
    }
}
