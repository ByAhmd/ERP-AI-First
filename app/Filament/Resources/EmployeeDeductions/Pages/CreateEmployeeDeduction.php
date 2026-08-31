<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeDeductions\Pages;

use App\Filament\Resources\EmployeeDeductions\EmployeeDeductionResource;
use App\Services\Accounting\DocumentNumberAllocator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateEmployeeDeduction extends CreateRecord
{
    protected static string $resource = EmployeeDeductionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();
        $data['reference'] = DB::transaction(
            fn (): string => app(DocumentNumberAllocator::class)->next(
                key: 'employee_deduction',
                defaults: ['prefix' => 'DED-', 'padding' => 5],
            ),
        );

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
