<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dimensions\Tables;

use App\Enums\DimensionScope;
use App\Models\Dimension;
use App\Services\Accounting\Exceptions\DimensionRuleViolation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DimensionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('accounting.dimensions.columns.code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('accounting.dimensions.columns.name'))
                    ->searchable()
                    ->description(fn (Dimension $record): ?string => $record->name_en),

                TextColumn::make('scope')
                    ->label(__('accounting.dimensions.columns.scope'))
                    ->badge(),

                TextColumn::make('values_count')
                    ->label(__('accounting.dimensions.columns.values'))
                    ->counts('values'),

                IconColumn::make('is_required')
                    ->label(__('accounting.dimensions.columns.required'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('accounting.dimensions.columns.active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->label(__('accounting.dimensions.columns.scope'))
                    ->options(fn (): array => collect(DimensionScope::cases())
                        ->mapWithKeys(fn (DimensionScope $c): array => [$c->value => $c->getLabel()])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label(__('accounting.dimensions.columns.active')),
            ])
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    ->action(function (Dimension $record): void {
                        try {
                            $record->delete();
                        } catch (DimensionRuleViolation $e) {
                            // A dimension recorded against entries cannot be
                            // removed; the message explains deactivating instead.
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('accounting.dimensions.notifications.deleted'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('sort_order');
    }
}
