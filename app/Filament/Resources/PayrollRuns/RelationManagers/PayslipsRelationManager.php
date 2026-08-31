<?php

declare(strict_types=1);

namespace App\Filament\Resources\PayrollRuns\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The run's payslips — the subledger rows, read-only always.
 */
class PayslipsRelationManager extends RelationManager
{
    protected static string $relationship = 'payslips';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('payroll.runs.slips.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee']))
            ->columns([
                TextColumn::make('employee.reference')
                    ->label(__('payroll.employees.columns.reference')),

                TextColumn::make('employee.first_name')
                    ->label(__('payroll.runs.slips.employee'))
                    ->formatStateUsing(fn ($record): string => $record->employee?->fullName() ?? '—'),

                TextColumn::make('base_salary')
                    ->label(__('payroll.runs.slips.base'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('allowances_total')
                    ->label(__('payroll.runs.slips.allowances'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('deductions_total')
                    ->label(__('payroll.runs.slips.deductions'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('advance_recovery')
                    ->label(__('payroll.runs.slips.recovery'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('gosi_employee')
                    ->label(__('payroll.runs.slips.gosi_employee'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('net')
                    ->label(__('payroll.runs.slips.net'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->weight('bold'),
            ])
            ->defaultSort('id')
            ->paginated([25, 50]);
    }
}
