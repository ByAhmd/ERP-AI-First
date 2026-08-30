<?php

declare(strict_types=1);

namespace App\Filament\Resources\Taxes;

use App\Enums\AccountType;
use App\Enums\TaxCategory;
use App\Filament\Resources\Taxes\Pages\CreateTax;
use App\Filament\Resources\Taxes\Pages\EditTax;
use App\Filament\Resources\Taxes\Pages\ListTaxes;
use App\Models\Account;
use App\Models\Tax;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * VAT rates.
 *
 * Presented as Qoyod presents it — the same columns in the same order, because
 * the people using this have been reading that screen for years and a
 * rearranged one costs them time for no benefit.
 *
 * The rate is configuration rather than a constant. Saudi VAT moved from 5% to
 * 15% within the lifetime of a working set of books, and documents raised
 * before the change must keep reporting what they were actually charged at.
 */
class TaxResource extends Resource
{
    protected static ?string $model = Tax::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('sales.taxes.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sales.taxes.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sales.taxes.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('sales.taxes.sections.details'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('sales.taxes.fields.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('sales.taxes.fields.name_en'))
                        ->maxLength(255),

                    Select::make('category')
                        ->label(__('sales.taxes.fields.category'))
                        ->options(TaxCategory::class)
                        ->helperText(__('sales.taxes.hints.category'))
                        ->default(TaxCategory::Standard)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),

                    TextInput::make('rate')
                        ->label(__('sales.taxes.fields.rate'))
                        ->helperText(__('sales.taxes.hints.rate'))
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->required()
                        // Zero-rated and exempt are defined by charging nothing.
                        // The observer refuses a rate on either; disabling the
                        // field says so before the refusal rather than after.
                        ->disabled(fn (Get $get): bool => ! self::categoryOf($get('category'))->allowsRate())
                        ->dehydrated(),
                ])
                ->columns(2),

            Section::make(__('sales.taxes.sections.posting'))
                ->schema([
                    Select::make('account_id')
                        ->label(__('sales.taxes.fields.account'))
                        ->helperText(__('sales.taxes.hints.account'))
                        ->options(fn (): array => Account::query()
                            ->where('is_postable', true)
                            ->whereIn('type', [AccountType::Liability, AccountType::Asset])
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a): array => [
                                $a->getKey() => $a->code.' - '.$a->name,
                            ])
                            ->all())
                        ->searchable()
                        ->required(),

                    Toggle::make('is_default')
                        ->label(__('sales.taxes.fields.is_default'))
                        ->helperText(__('sales.taxes.hints.is_default')),

                    Toggle::make('is_active')
                        ->label(__('sales.taxes.fields.is_active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Read the category out of form state.
     *
     * The state is an enum once a record is loaded or a default applied, and a
     * string while the select is being changed. Casting the enum to a string
     * throws, which is how the create form managed to return a 500 on every
     * visit while the model tests all passed.
     */
    private static function categoryOf(mixed $state): TaxCategory
    {
        if ($state instanceof TaxCategory) {
            return $state;
        }

        return is_string($state)
            ? TaxCategory::tryFrom($state) ?? TaxCategory::Standard
            : TaxCategory::Standard;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Qoyod numbers the rows rather than showing an identifier.
                TextColumn::make('index')
                    ->label(__('sales.taxes.columns.number'))
                    ->rowIndex(),

                TextColumn::make('name')
                    ->label(__('sales.taxes.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('sales.taxes.columns.code'))
                    ->badge(),

                TextColumn::make('rate')
                    ->label(__('sales.taxes.columns.rate'))
                    ->formatStateUsing(fn (Tax $record): string => $record->formattedRate())
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('account.code')
                    ->label(__('sales.taxes.columns.account'))
                    ->formatStateUsing(fn (Tax $record): string => $record->account === null
                        ? '—'
                        : $record->account->code.' - '.$record->account->name),

                IconColumn::make('is_default')
                    ->label(__('sales.taxes.columns.default'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('sales.taxes.columns.active'))
                    ->boolean(),
            ])
            ->defaultSort('created_at')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTaxes::route('/'),
            'create' => CreateTax::route('/create'),
            'edit' => EditTax::route('/{record}/edit'),
        ];
    }
}
