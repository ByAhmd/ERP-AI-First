<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dimensions\Schemas;

use App\Enums\DimensionScope;
use App\Models\Dimension;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DimensionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('accounting.dimensions.sections.identity'))
                ->schema([
                    TextInput::make('code')
                        ->label(__('accounting.dimensions.columns.code'))
                        ->required()
                        ->maxLength(30)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('accounting.dimensions.hints.code')),

                    TextInput::make('name')
                        ->label(__('accounting.dimensions.columns.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('accounting.dimensions.columns.name_en'))
                        ->maxLength(255),
                ])
                ->columns(3),

            Section::make(__('accounting.dimensions.sections.behaviour'))
                ->schema([
                    // Radio rather than a select: there are two options whose
                    // difference needs explaining, and only Radio and
                    // CheckboxList carry per-option descriptions in Filament.
                    // Showing both explanations at once is the point.
                    Radio::make('scope')
                        ->label(__('accounting.dimensions.columns.scope'))
                        ->options(collect(DimensionScope::cases())
                            ->mapWithKeys(fn (DimensionScope $c): array => [$c->value => $c->getLabel()])
                            ->all())
                        ->descriptions(collect(DimensionScope::cases())
                            ->mapWithKeys(fn (DimensionScope $c): array => [$c->value => $c->getDescription()])
                            ->all())
                        ->default(DimensionScope::Specific->value)
                        ->required()
                        // Changing scope once entries carry the dimension would
                        // restate every report sliced by it, so the field locks.
                        ->disabled(fn (?Dimension $record): bool => $record?->hasLedgerUsage() ?? false)
                        ->helperText(fn (?Dimension $record): string => ($record?->hasLedgerUsage() ?? false)
                            ? __('accounting.dimensions.hints.scope_locked')
                            : __('accounting.dimensions.hints.scope', ['limit' => DimensionScope::GENERAL_LIMIT])),

                    Toggle::make('is_required')
                        ->label(__('accounting.dimensions.columns.required'))
                        ->helperText(__('accounting.dimensions.hints.required')),

                    Toggle::make('is_active')
                        ->label(__('accounting.dimensions.columns.active'))
                        ->default(true)
                        ->helperText(__('accounting.dimensions.hints.active')),
                ])
                ->columns(3),
        ]);
    }
}
