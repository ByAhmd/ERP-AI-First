<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeBonuses\Pages;

use App\Filament\Resources\EmployeeBonuses\EmployeeBonusResource;
use App\Services\Payroll\EmployeeBonusPoster;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeBonus extends CreateRecord
{
    protected static string $resource = EmployeeBonusResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();
        $data['reference'] = app(EmployeeBonusPoster::class)->nextReference();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
