<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalaryComponents;

use App\Enums\AccountType;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentKind;
use App\Enums\SystemAccount;
use App\Filament\Resources\SalaryComponents\Pages\CreateSalaryComponent;
use App\Filament\Resources\SalaryComponents\Pages\EditSalaryComponent;
use App\Filament\Resources\SalaryComponents\Pages\ListSalaryComponents;
use App\Models\Account;
use App\Models\SalaryComponent;
use BackedEnum;
use Filament\Actions\DeleteAction;
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
 * Salary components — مكونات الرواتب: allowances as expense, recurring
 * deductions as income, each on its own account.
 */
class SalaryComponentResource extends Resource
{
    protected static ?string $model = SalaryComponent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('payroll.components.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.components.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.components.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('payroll.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->label(__('payroll.components.fields.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('payroll.components.fields.name_en'))
                        ->maxLength(255),

                    Select::make('kind')
                        ->label(__('payroll.components.fields.kind'))
                        ->options(SalaryComponentKind::class)
                        ->default(SalaryComponentKind::Allowance->value)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),

                    Select::make('calculation')
                        ->label(__('payroll.components.fields.calculation'))
                        ->options(SalaryComponentCalculation::class)
                        ->default(SalaryComponentCalculation::Fixed->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    Select::make('account_id')
                        ->label(__('payroll.components.fields.account'))
                        ->helperText(__('payroll.components.hints.account'))
                        ->options(function (Get $get): array {
                            $kind = $get('kind');
                            $type = ($kind instanceof SalaryComponentKind ? $kind : SalaryComponentKind::tryFrom((string) $kind))
                                === SalaryComponentKind::Deduction
                                ? AccountType::Revenue
                                : AccountType::Expense;

                            return Account::query()
                                ->where('is_postable', true)
                                ->where('is_active', true)
                                ->where('type', $type)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (Account $a): array => [
                                    $a->getKey() => $a->displayName(),
                                ])
                                ->all();
                        })
                        ->default(fn (): ?string => Account::query()
                            ->where('system_key', SystemAccount::SalariesExpense->value)
                            ->value('id'))
                        ->searchable()
                        ->required(),

                    Toggle::make('counts_toward_gosi')
                        ->label(__('payroll.components.fields.counts_toward_gosi'))
                        ->helperText(__('payroll.components.hints.counts_toward_gosi'))
                        ->inline(false),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('account')->withCount('assignments'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('payroll.components.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->label(__('payroll.components.columns.kind'))
                    ->badge(),

                TextColumn::make('calculation')
                    ->label(__('payroll.components.columns.calculation'))
                    ->formatStateUsing(fn (SalaryComponent $record): string => $record->calculation->getLabel()),

                TextColumn::make('account.name')
                    ->label(__('payroll.components.columns.account')),

                IconColumn::make('counts_toward_gosi')
                    ->label(__('payroll.components.columns.gosi'))
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    ->visible(fn (SalaryComponent $record): bool => ! $record->assignments()->exists()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSalaryComponents::route('/'),
            'create' => CreateSalaryComponent::route('/create'),
            'edit' => EditSalaryComponent::route('/{record}/edit'),
        ];
    }
}
