<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeDeductions;

use App\Enums\DeductionKind;
use App\Enums\DocumentStatus;
use App\Filament\Resources\EmployeeDeductions\Pages\CreateEmployeeDeduction;
use App\Filament\Resources\EmployeeDeductions\Pages\EditEmployeeDeduction;
use App\Filament\Resources\EmployeeDeductions\Pages\ListEmployeeDeductions;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
 * One-off deductions — الخصومات. No entry of their own: the payroll run
 * that consumes them posts the money.
 */
class EmployeeDeductionResource extends Resource
{
    protected static ?string $model = EmployeeDeduction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('payroll.deductions.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.deductions.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.deductions.nav_label');
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
                        ->label(__('payroll.deductions.fields.employee'))
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
                        ->label(__('payroll.deductions.fields.kind'))
                        ->options(DeductionKind::class)
                        ->default(DeductionKind::Violation->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('payroll.deductions.fields.amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),

                    DatePicker::make('deduction_date')
                        ->label(__('payroll.deductions.fields.date'))
                        ->helperText(__('payroll.deductions.hints.date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    TextInput::make('description')
                        ->label(__('payroll.deductions.fields.description'))
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
                    ->label(__('payroll.deductions.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.first_name')
                    ->label(__('payroll.deductions.columns.employee'))
                    ->formatStateUsing(fn (EmployeeDeduction $record): string => $record->employee?->fullName() ?? '—'),

                TextColumn::make('kind')
                    ->label(__('payroll.deductions.columns.kind'))
                    ->formatStateUsing(fn (EmployeeDeduction $record): string => $record->kind->getLabel()),

                TextColumn::make('amount')
                    ->label(__('payroll.deductions.columns.amount'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('deduction_date')
                    ->label(__('payroll.deductions.columns.date'))
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('payroll.deductions.columns.status'))
                    ->badge(),

                TextColumn::make('payslip_id')
                    ->label(__('payroll.deductions.columns.consumed'))
                    ->formatStateUsing(fn (): string => __('payroll.deductions.consumed_by_run'))
                    ->placeholder(__('payroll.deductions.pending'))
                    ->badge()
                    ->color('success'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payroll.deductions.columns.status'))
                    ->options(DocumentStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (EmployeeDeduction $record): bool => $record->isDraft()),

                Action::make('approve')
                    ->label(__('payroll.deductions.actions.approve'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (EmployeeDeduction $record): bool => $record->isDraft())
                    ->requiresConfirmation()
                    ->action(function (EmployeeDeduction $record): void {
                        $record->forceFill(['status' => DocumentStatus::Approved])->save();

                        Notification::make()
                            ->title(__('payroll.deductions.actions.approved'))
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->visible(fn (EmployeeDeduction $record): bool => $record->isDraft()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeDeductions::route('/'),
            'create' => CreateEmployeeDeduction::route('/create'),
            'edit' => EditEmployeeDeduction::route('/{record}/edit'),
        ];
    }
}
