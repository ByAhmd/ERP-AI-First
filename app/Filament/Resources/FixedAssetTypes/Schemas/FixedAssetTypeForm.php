<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetTypes\Schemas;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\FixedAssetType;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The classification form.
 *
 * Three account selects, each filtered to postable accounts of the right
 * kind and defaulted from the keyed system accounts — the accounts a
 * company gets without thinking, while per-class children stay reachable.
 * The accounts and the depreciable flag lock once assets of the type carry
 * charges or disposals.
 */
class FixedAssetTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('assets.types.sections.details'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('assets.types.fields.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('assets.types.fields.name_en'))
                        ->maxLength(255),

                    TextInput::make('description')
                        ->label(__('assets.types.fields.description'))
                        ->maxLength(255),

                    TextInput::make('default_useful_life_months')
                        ->label(__('assets.types.fields.default_life'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(1200),

                    Toggle::make('is_depreciable')
                        ->label(__('assets.types.fields.is_depreciable'))
                        ->helperText(__('assets.types.hints.is_depreciable'))
                        ->default(true)
                        ->inline(false)
                        ->disabled(fn (?FixedAssetType $record): bool => $record !== null
                            && $record->isStructureLocked()),
                ])
                ->columns(3),

            Section::make(__('assets.types.sections.accounts'))
                ->description(__('assets.types.hints.accounts'))
                ->schema([
                    self::accountSelect(
                        'asset_account_id',
                        __('assets.types.fields.asset_account'),
                        AccountType::Asset,
                        SystemAccount::FixedAssets,
                    ),

                    self::accountSelect(
                        'accumulated_depreciation_account_id',
                        __('assets.types.fields.accumulated_account'),
                        AccountType::Asset,
                        SystemAccount::AccumulatedDepreciation,
                    ),

                    self::accountSelect(
                        'depreciation_expense_account_id',
                        __('assets.types.fields.expense_account'),
                        AccountType::Expense,
                        SystemAccount::DepreciationExpense,
                    ),
                ])
                ->columns(3),
        ]);
    }

    private static function accountSelect(
        string $field,
        string $label,
        AccountType $type,
        SystemAccount $default,
    ): Select {
        return Select::make($field)
            ->label($label)
            ->options(fn (): array => Account::query()
                ->where('is_postable', true)
                ->where('is_active', true)
                ->where('type', $type)
                ->orderBy('code')
                ->get()
                ->mapWithKeys(fn (Account $a): array => [
                    $a->getKey() => $a->displayName(),
                ])
                ->all())
            ->default(fn (): ?string => Account::query()
                ->where('system_key', $default->value)
                ->value('id'))
            ->searchable()
            ->required()
            ->disabled(fn (?FixedAssetType $record): bool => $record !== null
                && $record->isStructureLocked());
    }
}
