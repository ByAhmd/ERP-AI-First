<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeePaymentVouchers\Pages;

use App\Filament\Resources\EmployeePaymentVouchers\EmployeePaymentVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeePaymentVouchers extends ListRecords
{
    protected static string $resource = EmployeePaymentVoucherResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
