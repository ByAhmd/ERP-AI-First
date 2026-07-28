<?php

declare(strict_types=1);

namespace App\Filament\Resources\Audits\Tables;

use App\Models\Audit;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AuditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('audit.columns.at'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label(__('audit.columns.actor'))
                    // System and queue activity has no acting user; saying so is
                    // clearer than an empty cell.
                    ->placeholder(__('audit.system_actor'))
                    ->searchable(),

                TextColumn::make('event')
                    ->label(__('audit.columns.event'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('auditable_type')
                    ->label(__('audit.columns.record'))
                    ->formatStateUsing(fn (Audit $record): string => $record->auditableLabel())
                    ->searchable(),

                TextColumn::make('ip_address')
                    ->label(__('audit.columns.ip'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label(__('audit.columns.event'))
                    ->options([
                        'created' => __('audit.events.created'),
                        'updated' => __('audit.events.updated'),
                        'deleted' => __('audit.events.deleted'),
                        'restored' => __('audit.events.restored'),
                    ]),

                Filter::make('recent')
                    ->label(__('audit.filters.last_7_days'))
                    ->query(fn (Builder $query): Builder => $query->where(
                        'created_at',
                        '>=',
                        Carbon::now()->subDays(7),
                    )),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        Section::make(__('audit.detail.summary'))
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label(__('audit.columns.at'))
                                    ->dateTime(),
                                TextEntry::make('user.name')
                                    ->label(__('audit.columns.actor'))
                                    ->placeholder(__('audit.system_actor')),
                                TextEntry::make('event')
                                    ->label(__('audit.columns.event'))
                                    ->badge(),
                                TextEntry::make('auditable_type')
                                    ->label(__('audit.columns.record'))
                                    ->formatStateUsing(fn (Audit $record): string => $record->auditableLabel()),
                            ])
                            ->columns(2),

                        Section::make(__('audit.detail.changes'))
                            ->schema([
                                KeyValueEntry::make('old_values')
                                    ->label(__('audit.detail.before')),
                                KeyValueEntry::make('new_values')
                                    ->label(__('audit.detail.after')),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
