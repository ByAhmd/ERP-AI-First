<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('accounting.accounts.sections.identity'))
                ->schema([
                    TextInput::make('code')
                        ->label(__('accounting.accounts.columns.code'))
                        ->required()
                        ->maxLength(30)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('accounting.accounts.hints.code')),

                    Select::make('type')
                        ->label(__('accounting.accounts.columns.type'))
                        ->options(collect(AccountType::cases())
                            ->mapWithKeys(fn (AccountType $case): array => [$case->value => $case->getLabel()])
                            ->all())
                        ->required()
                        // Reclassifying an account with history would move past
                        // amounts between the balance sheet and the income
                        // statement, restating reports already issued.
                        ->disabled(fn (?Account $record): bool => $record?->hasLedgerHistory() ?? false)
                        ->helperText(fn (?Account $record): ?string => ($record?->hasLedgerHistory() ?? false)
                            ? __('accounting.accounts.hints.type_locked')
                            : null),

                    TextInput::make('name')
                        ->label(__('accounting.accounts.columns.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('accounting.accounts.columns.name_en'))
                        ->maxLength(255),
                ])
                ->columns(2),

            Section::make(__('accounting.accounts.sections.placement'))
                ->schema([
                    Select::make('parent_id')
                        ->label(__('accounting.accounts.columns.parent'))
                        ->relationship(
                            name: 'parent',
                            titleAttribute: 'name',
                            // An account cannot be its own ancestor, and moving
                            // a parent beneath its own child would detach the
                            // subtree from the chart.
                            modifyQueryUsing: fn (Builder $query, ?Account $record) => $query
                                ->when($record, fn (Builder $q) => $q
                                    ->whereKeyNot($record->getKey())
                                    ->where(function (Builder $q) use ($record): void {
                                        $q->whereNull('path')
                                            ->orWhere('path', 'not like', $record->path.'.%');
                                    }))
                                ->orderBy('code'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Account $record): string => $record->displayName())
                        ->searchable()
                        ->preload()
                        ->helperText(__('accounting.accounts.hints.parent')),

                    Toggle::make('is_active')
                        ->label(__('accounting.accounts.columns.active'))
                        ->default(true)
                        ->helperText(__('accounting.accounts.hints.active')),
                ])
                ->columns(2),

            Section::make(__('accounting.accounts.sections.notes'))
                ->schema([
                    Textarea::make('description')
                        ->label(__('accounting.accounts.columns.description'))
                        ->rows(2)
                        ->maxLength(1000),
                ])
                ->collapsed(),
        ]);
    }
}
