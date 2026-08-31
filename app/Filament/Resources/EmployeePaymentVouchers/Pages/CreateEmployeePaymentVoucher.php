<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeePaymentVouchers\Pages;

use App\Filament\Resources\EmployeePaymentVouchers\EmployeePaymentVoucherResource;
use App\Services\Payroll\EmployeePaymentPoster;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeePaymentVoucher extends CreateRecord
{
    protected static string $resource = EmployeePaymentVoucherResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();
        $data['reference'] = app(EmployeePaymentPoster::class)->nextReference();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
