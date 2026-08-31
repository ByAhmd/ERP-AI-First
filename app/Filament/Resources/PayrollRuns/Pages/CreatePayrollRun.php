<?php

declare(strict_types=1);

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Models\PayrollRunExclusion;
use App\Services\Payroll\PayrollRunEngine;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreatePayrollRun extends CreateRecord
{
    protected static string $resource = PayrollRunResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();
        $data['reference'] = app(PayrollRunEngine::class)->nextReference();

        return $data;
    }

    protected function afterCreate(): void
    {
        foreach ((array) ($this->data['excluded_employee_ids'] ?? []) as $employeeId) {
            PayrollRunExclusion::create([
                'payroll_run_id' => $this->getRecord()->getKey(),
                'employee_id' => $employeeId,
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
