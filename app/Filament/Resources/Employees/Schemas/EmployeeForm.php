<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\EmployeeCostType;
use App\Enums\EmployeeStatus;
use App\Enums\NationalityStatus;
use App\Enums\SalaryComponentCalculation;
use App\Models\Branch;
use App\Models\SalaryComponent;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The employee form — Qoyod's tabs flattened into sections: identity,
 * employment, the salary definition and GOSI, with the component
 * assignments as a repeater.
 */
class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('payroll.employees.sections.identity'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('payroll.employees.fields.reference'))
                        ->required()
                        ->maxLength(40),

                    TextInput::make('first_name')
                        ->label(__('payroll.employees.fields.first_name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('last_name')
                        ->label(__('payroll.employees.fields.last_name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('first_name_en')
                        ->label(__('payroll.employees.fields.first_name_en'))
                        ->maxLength(255),

                    TextInput::make('last_name_en')
                        ->label(__('payroll.employees.fields.last_name_en'))
                        ->maxLength(255),

                    Select::make('nationality_status')
                        ->label(__('payroll.employees.fields.nationality_status'))
                        ->options(NationalityStatus::class)
                        ->default(NationalityStatus::Saudi->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('national_id')
                        ->label(__('payroll.employees.fields.national_id'))
                        ->maxLength(40),

                    TextInput::make('iban')
                        ->label(__('payroll.employees.fields.iban'))
                        ->maxLength(40),

                    DatePicker::make('birth_date')
                        ->label(__('payroll.employees.fields.birth_date'))
                        ->native(false),

                    TextInput::make('email')
                        ->label(__('payroll.employees.fields.email'))
                        ->email()
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->label(__('payroll.employees.fields.phone'))
                        ->maxLength(40),
                ])
                ->columns(3),

            Section::make(__('payroll.employees.sections.employment'))
                ->schema([
                    Select::make('branch_id')
                        ->label(__('payroll.employees.fields.branch'))
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
                        ->required(),

                    TextInput::make('department')
                        ->label(__('payroll.employees.fields.department'))
                        ->maxLength(255),

                    TextInput::make('job_title')
                        ->label(__('payroll.employees.fields.job_title'))
                        ->maxLength(255),

                    TextInput::make('education_level')
                        ->label(__('payroll.employees.fields.education_level'))
                        ->maxLength(255),

                    DatePicker::make('joined_on')
                        ->label(__('payroll.employees.fields.joined_on'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    Select::make('cost_type')
                        ->label(__('payroll.employees.fields.cost_type'))
                        ->options(EmployeeCostType::class)
                        ->default(EmployeeCostType::Indirect->value)
                        ->selectablePlaceholder(false)
                        ->required(),

                    Select::make('status')
                        ->label(__('payroll.employees.fields.status'))
                        ->options(EmployeeStatus::class)
                        ->default(EmployeeStatus::Active->value)
                        ->selectablePlaceholder(false)
                        ->required(),
                ])
                ->columns(3),

            Section::make(__('payroll.employees.sections.salary'))
                ->schema([
                    TextInput::make('base_salary')
                        ->label(__('payroll.employees.fields.base_salary'))
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    DatePicker::make('first_salary_date')
                        ->label(__('payroll.employees.fields.first_salary_date'))
                        ->helperText(__('payroll.employees.hints.first_salary_date'))
                        ->native(false)
                        ->default(now())
                        ->required(),

                    DatePicker::make('last_salary_date')
                        ->label(__('payroll.employees.fields.last_salary_date'))
                        ->helperText(__('payroll.employees.hints.last_salary_date'))
                        ->native(false),
                ])
                ->columns(3),

            Section::make(__('payroll.employees.sections.gosi'))
                ->schema([
                    Toggle::make('gosi_enrolled')
                        ->label(__('payroll.employees.fields.gosi_enrolled'))
                        ->inline(false)
                        ->live(),

                    TextInput::make('gosi_wage')
                        ->label(__('payroll.employees.fields.gosi_wage'))
                        ->helperText(__('payroll.employees.hints.gosi_wage'))
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => (bool) $get('gosi_enrolled')),
                ])
                ->columns(3),

            Section::make(__('payroll.employees.sections.components'))
                ->schema([
                    Repeater::make('salaryComponents')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make(__('payroll.employees.fields.component'))->width('60%'),
                            TableColumn::make(__('payroll.employees.fields.component_amount'))->width('40%')->alignEnd(),
                        ])
                        ->schema([
                            Select::make('salary_component_id')
                                ->options(fn (): array => SalaryComponent::query()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (SalaryComponent $c): array => [
                                        $c->getKey() => $c->displayName()
                                            .' ('.$c->kind->getLabel()
                                            .($c->calculation === SalaryComponentCalculation::PercentOfBase ? ' %' : '').')',
                                    ])
                                    ->all())
                                ->searchable()
                                ->required(),

                            TextInput::make('amount')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                        ])
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->addActionLabel(__('payroll.employees.fields.component')),
                ]),
        ]);
    }
}
