<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeBonuses;

use App\Enums\BonusKind;
use App\Enums\DocumentStatus;
use App\Filament\Resources\EmployeeBonuses\Pages\CreateEmployeeBonus;
use App\Filament\Resources\EmployeeBonuses\Pages\EditEmployeeBonus;
use App\Filament\Resources\EmployeeBonuses\Pages\ListEmployeeBonuses;
use App\Filament\Resources\EmployeeBonuses\Pages\ViewEmployeeBonus;
use App\Models\Employee;
use App\Models\EmployeeBonus;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\EmployeeBonusPoster;
use App\Services\Payroll\Exceptions\PayrollRuleViolation;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Bonuses — المكافآت.
 */
class EmployeeBonusResource extends Resource
{
    protected static ?string $model = EmployeeBonus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('payroll.bonuses.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.bonuses.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.bonuses.nav_label');
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
                    Select::make('employee_id')
                        ->label(__('payroll.bonuses.fields.employee'))
                        ->options(fn (): array => Employee::query()
                            ->whereIn('status', ['active', 'terminated'])
                            ->orderBy('reference')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [
                                $e->getKey() => $e->reference.' — '.$e->fullName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),

                    Select::make('kind')
                        ->label(__('payroll.bonuses.fields.kind'))
                        ->options(BonusKind::class)
                        ->default(BonusKind::Grant->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('payroll.bonuses.fields.amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),

                    DatePicker::make('bonus_date')
                        ->label(__('payroll.bonuses.fields.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('notes')
                        ->label(__('payroll.bonuses.fields.notes'))
                        ->maxLength(255),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('payroll.bonuses.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.first_name')
                    ->label(__('payroll.bonuses.columns.employee'))
                    ->formatStateUsing(fn (EmployeeBonus $record): string => $record->employee?->fullName() ?? '—'),

                TextColumn::make('kind')
                    ->label(__('payroll.bonuses.columns.kind'))
                    ->formatStateUsing(fn (EmployeeBonus $record): string => $record->kind->getLabel()),

                TextColumn::make('amount')
                    ->label(__('payroll.bonuses.columns.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('bonus_date')
                    ->label(__('payroll.bonuses.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('payroll.bonuses.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payroll.bonuses.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (EmployeeBonus $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (EmployeeBonus $record): bool => ! $record->isDraft()),

                Action::make('reverse')
                    ->label(__('payroll.bonuses.actions.reverse'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (EmployeeBonus $record): bool => $record->isApproved())
                    ->requiresConfirmation()
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('payroll.bonuses.actions.reversal_date'))
                            ->native(false)
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (EmployeeBonus $record, array $data, EmployeeBonusPoster $poster): void {
                        try {
                            $poster->reverse($record, CarbonImmutable::parse($data['date']), Filament::auth()->id());
                        } catch (PayrollRuleViolation|PostingRejected $refusal) {
                            Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title(__('payroll.bonuses.actions.reversed'))->success()->send();
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeBonuses::route('/'),
            'create' => CreateEmployeeBonus::route('/create'),
            'edit' => EditEmployeeBonus::route('/{record}/edit'),
            'view' => ViewEmployeeBonus::route('/{record}'),
        ];
    }
}
