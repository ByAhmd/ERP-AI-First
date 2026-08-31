<?php

declare(strict_types=1);

namespace App\Filament\Resources\PayrollRuns;

use App\Enums\DocumentStatus;
use App\Filament\Resources\PayrollRuns\Pages\CreatePayrollRun;
use App\Filament\Resources\PayrollRuns\Pages\EditPayrollRun;
use App\Filament\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Payroll\Exceptions\PayrollRunRejected;
use App\Services\Payroll\PayrollRunEngine;
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
 * Payroll runs — مسير الرواتب.
 */
class PayrollRunResource extends Resource
{
    protected static ?string $model = PayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('payroll.runs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.runs.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.runs.nav_label');
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
                    Select::make('accounting_period_id')
                        ->label(__('payroll.runs.fields.period'))
                        ->helperText(__('payroll.runs.hints.period'))
                        ->options(fn (): array => AccountingPeriod::query()
                            ->orderByDesc('start_date')
                            ->limit(24)
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?string => AccountingPeriod::query()
                            ->whereDate('start_date', '<=', now())
                            ->whereDate('end_date', '>=', now())
                            ->value('id'))
                        ->required(),

                    TextInput::make('notes')
                        ->label(__('payroll.runs.fields.notes'))
                        ->maxLength(255),

                    Select::make('excluded_employee_ids')
                        ->label(__('payroll.runs.fields.excluded'))
                        ->helperText(__('payroll.runs.hints.excluded'))
                        ->multiple()
                        ->options(fn (): array => Employee::query()
                            ->whereIn('status', ['active', 'terminated'])
                            ->orderBy('reference')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [
                                $e->getKey() => $e->reference.' — '.$e->fullName(),
                            ])
                            ->all())
                        ->dehydrated(false),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['period']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('payroll.runs.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('period.name')
                    ->label(__('payroll.runs.columns.period')),

                TextColumn::make('run_date')
                    ->label(__('payroll.runs.columns.run_date'))
                    ->date('d M Y')
                    ->placeholder('—'),

                TextColumn::make('employees_count')
                    ->label(__('payroll.runs.columns.employees_count'))
                    ->alignEnd(),

                TextColumn::make('net_total')
                    ->label(__('payroll.runs.columns.net_total'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label(__('payroll.runs.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payroll.runs.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (PayrollRun $record): bool => $record->isDraft()),

                ViewAction::make()
                    ->visible(fn (PayrollRun $record): bool => ! $record->isDraft()),

                self::reverseAction(),
            ]);
    }

    public static function reverseAction(): Action
    {
        return Action::make('reverse')
            ->label(__('payroll.runs.actions.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (PayrollRun $record): bool => $record->isApproved())
            ->requiresConfirmation()
            ->modalDescription(__('payroll.runs.actions.reverse_confirm'))
            ->schema([
                DatePicker::make('date')
                    ->label(__('payroll.runs.actions.reversal_date'))
                    ->native(false)
                    ->default(now())
                    ->required(),
            ])
            ->action(function (PayrollRun $record, array $data, PayrollRunEngine $engine): void {
                try {
                    $engine->reverse(
                        $record,
                        CarbonImmutable::parse($data['date']),
                        Filament::auth()->id(),
                    );
                } catch (PayrollRunRejected|PostingRejected $refusal) {
                    Notification::make()
                        ->title($refusal->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('payroll.runs.actions.reversed'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\PayslipsRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRuns::route('/'),
            'create' => CreatePayrollRun::route('/create'),
            'edit' => EditPayrollRun::route('/{record}/edit'),
            'view' => ViewPayrollRun::route('/{record}'),
        ];
    }
}
