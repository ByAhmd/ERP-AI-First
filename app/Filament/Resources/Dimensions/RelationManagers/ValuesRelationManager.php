<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dimensions\RelationManagers;

use App\Models\DimensionValue;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Values belonging to a dimension.
 *
 * Managed from inside the dimension rather than as a resource of their own,
 * which is how Qoyod arranges it — a value has no meaning apart from the
 * dimension it belongs to.
 */
class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('accounting.dimensions.values.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('accounting.dimensions.columns.code'))
                ->required()
                ->maxLength(30)
                // Unique within the dimension, not globally: two dimensions may
                // each have an "OPS" value without conflict.
                ->unique(
                    modifyRuleUsing: fn ($rule) => $rule->where('dimension_id', $this->getOwnerRecord()->getKey()),
                    ignoreRecord: true,
                ),

            TextInput::make('name')
                ->label(__('accounting.dimensions.columns.name'))
                ->required()
                ->maxLength(255),

            TextInput::make('name_en')
                ->label(__('accounting.dimensions.columns.name_en'))
                ->maxLength(255),

            Select::make('parent_id')
                ->label(__('accounting.dimensions.columns.parent'))
                ->options(fn (?DimensionValue $record): array => DimensionValue::query()
                    ->where('dimension_id', $this->getOwnerRecord()->getKey())
                    ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (DimensionValue $v): array => [$v->getKey() => $v->displayName()])
                    ->all())
                ->searchable()
                ->helperText(__('accounting.dimensions.hints.parent')),

            Toggle::make('is_active')
                ->label(__('accounting.dimensions.columns.active'))
                ->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
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
                    ->description(fn (DimensionValue $record): ?string => $record->name_en),

                TextColumn::make('parent.name')
                    ->label(__('accounting.dimensions.columns.parent'))
                    ->placeholder('—'),

                IconColumn::make('is_active')
                    ->label(__('accounting.dimensions.columns.active'))
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('accounting.dimensions.values.add')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('code');
    }
}
