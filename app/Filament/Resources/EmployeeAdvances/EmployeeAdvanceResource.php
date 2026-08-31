<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeAdvances;

use App\Enums\AdvanceKind;
use App\Enums\DocumentStatus;
use App\Filament\Resources\EmployeeAdvances\Pages\CreateEmployeeAdvance;
use App\Filament\Resources\EmployeeAdvances\Pages\EditEmployeeAdvance;
use App\Filament\Resources\EmployeeAdvances\Pages\ListEmployeeAdvances;
use App\Filament\Resources\EmployeeAdvances\Pages\ViewEmployeeAdvance;
use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\EmployeeAdvancePoster;
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
 * Employee advances — السلف.
 */
class EmployeeAdvanceResource extends Resource
{
    protected static ?string $model = EmployeeAdvance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('payroll.advances.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.advances.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.advances.nav_label');
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
                        ->label(__('payroll.advances.fields.employee'))
                        ->options(fn (): array => Employee::query()
                            ->where('status', 'active')
                            ->orderBy('reference')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [
                                $e->getKey() => $e->reference.' — '.$e->fullName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),

                    Select::make('kind')
                        ->label(__('payroll.advances.fields.kind'))
                        ->options(AdvanceKind::class)
                        ->default(AdvanceKind::Advance->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('payroll.advances.fields.amount'))
                        ->helperText(__('payroll.advances.hints.recovery'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),

                    DatePicker::make('advance_date')
                        ->label(__('payroll.advances.fields.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    Select::make('payment_account_id')
                        ->label(__('payroll.advances.fields.payment_account'))
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
                        ->required(),

                    TextInput::make('notes')
                        ->label(__('payroll.advances.fields.notes'))
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
                    ->label(__('payroll.advances.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.first_name')
                    ->label(__('payroll.advances.columns.employee'))
                    ->formatStateUsing(fn (EmployeeAdvance $record): string => $record->employee?->fullName() ?? '—'),

                TextColumn::make('kind')
                    ->label(__('payroll.advances.columns.kind'))
                    ->formatStateUsing(fn (EmployeeAdvance $record): string => $record->kind->getLabel()),

                TextColumn::make('amount')
                    ->label(__('payroll.advances.columns.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label(__('payroll.advances.columns.remaining'))
                    ->state(fn (EmployeeAdvance $record): string => $record->isApproved()
                        ? number_format((float) $record->remaining(), 2)
                        : '—')
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('advance_date')
                    ->label(__('payroll.advances.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('payroll.advances.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payroll.advances.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (EmployeeAdvance $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (EmployeeAdvance $record): bool => ! $record->isDraft()),

                Action::make('settle')
                    ->label(__('payroll.advances.actions.settle'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('info')
                    ->visible(fn (EmployeeAdvance $record): bool => $record->isApproved()
                        && bccomp($record->remaining(), '0', 4) > 0)
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('payroll.advances.fields.settlement_amount'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),

                        DatePicker::make('date')
                            ->label(__('payroll.advances.fields.settlement_date'))
                            ->native(false)
                            ->default(now())
                            ->required(),

                        Select::make('payment_account_id')
                            ->label(__('payroll.advances.fields.settlement_account'))
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
                            ->required(),
                    ])
                    ->action(function (EmployeeAdvance $record, array $data, EmployeeAdvancePoster $poster): void {
                        try {
                            $poster->settle(
                                $record,
                                (string) $data['amount'],
                                CarbonImmutable::parse($data['date']),
                                (string) $data['payment_account_id'],
                                Filament::auth()->id(),
                            );
                        } catch (PayrollRuleViolation|PostingRejected $refusal) {
                            Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title(__('payroll.advances.actions.settled'))->success()->send();
                    }),

                Action::make('reverse')
                    ->label(__('payroll.advances.actions.reverse'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('warning')
                    ->visible(fn (EmployeeAdvance $record): bool => $record->isApproved()
                        && ! $record->hasRepayments())
                    ->requiresConfirmation()
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('payroll.advances.actions.reversal_date'))
                            ->native(false)
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (EmployeeAdvance $record, array $data, EmployeeAdvancePoster $poster): void {
                        try {
                            $poster->reverse($record, CarbonImmutable::parse($data['date']), Filament::auth()->id());
                        } catch (PayrollRuleViolation|PostingRejected $refusal) {
                            Notification::make()->title($refusal->getMessage())->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()->title(__('payroll.advances.actions.reversed'))->success()->send();
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeAdvances::route('/'),
            'create' => CreateEmployeeAdvance::route('/create'),
            'edit' => EditEmployeeAdvance::route('/{record}/edit'),
            'view' => ViewEmployeeAdvance::route('/{record}'),
        ];
    }
}
