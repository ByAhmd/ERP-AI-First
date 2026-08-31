<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees;

use App\Enums\EmployeeStatus;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The employee register — الموظفون.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('payroll.employees.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payroll.employees.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.employees.nav_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('payroll.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('branch'))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('payroll.employees.columns.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('first_name')
                    ->label(__('payroll.employees.columns.name'))
                    ->formatStateUsing(fn (Employee $record): string => $record->fullName())
                    ->searchable(['first_name', 'last_name']),

                TextColumn::make('job_title')
                    ->label(__('payroll.employees.columns.job_title'))
                    ->placeholder('—'),

                TextColumn::make('department')
                    ->label(__('payroll.employees.columns.department'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('branch.name')
                    ->label(__('payroll.employees.columns.branch')),

                TextColumn::make('base_salary')
                    ->label(__('payroll.employees.columns.base_salary'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label(__('payroll.employees.columns.status'))
                    ->badge(),
            ])
            ->defaultSort('reference')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payroll.employees.columns.status'))
                    ->options(EmployeeStatus::class)
                    ->default(EmployeeStatus::Active->value),
            ])
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
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
