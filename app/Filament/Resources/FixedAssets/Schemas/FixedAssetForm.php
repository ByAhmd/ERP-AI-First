<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\Schemas;

use App\Enums\AssetAcquisitionKind;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Models\FixedAssetType;
use App\Models\Tax;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * The asset form.
 *
 * Creation goes through the registrar, never a bare model create: the
 * reference, the acquisition entry and the register row share a fate there.
 * Cost and salvage appear only after a classification is chosen — Qoyod's
 * quirk, cheap to honor — and the classification's default life copies in.
 *
 * On edit, the financial fields freeze once anything posted against the
 * asset; the descriptive fields stay open for good.
 */
class FixedAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        $locked = fn (?FixedAsset $record): bool => $record !== null
            && $record->isFinanciallyLocked();

        return $schema->components([
            Section::make(__('assets.register.sections.details'))
                ->schema([
                    Select::make('fixed_asset_type_id')
                        ->label(__('assets.register.fields.type'))
                        ->options(fn (): array => FixedAssetType::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (FixedAssetType $t): array => [
                                $t->getKey() => $t->displayName(),
                            ])
                            ->all())
                        ->required()
                        ->live()
                        // Qoyod loads the classification's accounts and
                        // defaults automatically; the life copies in the
                        // same spirit.
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if ($state === null) {
                                return;
                            }

                            $type = FixedAssetType::query()->find($state);

                            if ($type?->default_useful_life_months !== null) {
                                $set('useful_life_months', $type->default_useful_life_months);
                            }
                        })
                        ->disabled($locked),

                    TextInput::make('name')
                        ->label(__('assets.register.fields.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('assets.register.fields.name_en'))
                        ->maxLength(255),

                    TextInput::make('description')
                        ->label(__('assets.register.fields.description'))
                        ->maxLength(255),

                    TextInput::make('serial_number')
                        ->label(__('assets.register.fields.serial_number'))
                        ->maxLength(255),

                    TextInput::make('barcode')
                        ->label(__('assets.register.fields.barcode'))
                        ->maxLength(255),

                    Select::make('branch_id')
                        ->label(__('assets.register.fields.branch'))
                        ->options(fn (): array => Branch::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Branch $b): array => [
                                $b->getKey() => $b->displayName(),
                            ])
                            ->all())
                        ->default(fn (): ?string => Branch::query()
                            ->where('is_default', true)->value('id'))
                        ->required()
                        ->disabled($locked),
                ])
                ->columns(3),

            Section::make(__('assets.register.sections.acquisition'))
                ->schema([
                    Select::make('acquisition_kind')
                        ->label(__('assets.register.fields.acquisition_kind'))
                        // Bill is reserved for the from-bill slice; nothing
                        // may pick it by hand.
                        ->options([
                            AssetAcquisitionKind::Purchase->value => AssetAcquisitionKind::Purchase->getLabel(),
                            AssetAcquisitionKind::Opening->value => AssetAcquisitionKind::Opening->getLabel(),
                        ])
                        ->default(AssetAcquisitionKind::Purchase->value)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->hiddenOn('edit'),

                    DatePicker::make('acquisition_date')
                        ->label(__('assets.register.fields.acquisition_date'))
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->disabled($locked),

                    DatePicker::make('in_service_date')
                        ->label(__('assets.register.fields.in_service_date'))
                        ->helperText(__('assets.register.hints.in_service_date'))
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->disabled($locked),

                    TextInput::make('cost')
                        ->label(__('assets.register.fields.cost'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->visible(fn (Get $get): bool => filled($get('fixed_asset_type_id')))
                        ->disabled($locked),

                    TextInput::make('salvage_value')
                        ->label(__('assets.register.fields.salvage_value'))
                        ->helperText(__('assets.register.hints.salvage_value'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->visible(fn (Get $get): bool => filled($get('fixed_asset_type_id')))
                        ->disabled($locked),

                    TextInput::make('useful_life_months')
                        ->label(__('assets.register.fields.useful_life_months'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(1200)
                        ->visible(fn (Get $get, ?FixedAsset $record): bool => $record !== null
                            ? $record->is_depreciable
                            : self::typeIsDepreciable($get))
                        ->required(fn (Get $get, ?FixedAsset $record): bool => $record !== null
                            ? $record->is_depreciable
                            : self::typeIsDepreciable($get))
                        ->disabled($locked),
                ])
                ->columns(3),

            Section::make(__('assets.register.sections.opening'))
                ->description(__('assets.register.hints.opening'))
                ->schema([
                    TextInput::make('opening_accumulated_depreciation')
                        ->label(__('assets.register.fields.opening_accumulated'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    DatePicker::make('opening_depreciated_through')
                        ->label(__('assets.register.fields.opening_depreciated_through'))
                        ->helperText(__('assets.register.hints.opening_depreciated_through'))
                        ->native(false),

                    Toggle::make('register_only')
                        ->label(__('assets.register.fields.register_only'))
                        ->helperText(__('assets.register.hints.register_only'))
                        ->default(false)
                        ->inline(false),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => $get('acquisition_kind') === AssetAcquisitionKind::Opening->value)
                ->hiddenOn('edit'),

            Section::make(__('assets.register.sections.payment'))
                ->schema([
                    Select::make('payment_account_id')
                        ->label(__('assets.register.fields.payment_account'))
                        ->options(fn (): array => Account::query()
                            ->where('is_payment_account', true)
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a): array => [
                                $a->getKey() => $a->displayName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required(fn (Get $get): bool => $get('acquisition_kind') === AssetAcquisitionKind::Purchase->value),

                    Select::make('tax_id')
                        ->label(__('assets.register.fields.tax'))
                        ->options(fn (): array => Tax::query()
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (Tax $t): array => [
                                $t->getKey() => $t->displayName(),
                            ])
                            ->all()),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => $get('acquisition_kind') === AssetAcquisitionKind::Purchase->value)
                ->hiddenOn('edit'),
        ]);
    }

    private static function typeIsDepreciable(Get $get): bool
    {
        $typeId = $get('fixed_asset_type_id');

        if (blank($typeId)) {
            return false;
        }

        return (bool) FixedAssetType::query()
            ->whereKey($typeId)
            ->value('is_depreciable');
    }
}
