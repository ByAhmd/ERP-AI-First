<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeePaymentVouchers\Pages;

use App\Filament\Resources\EmployeePaymentVouchers\EmployeePaymentVoucherResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeePaymentVoucher extends ViewRecord
{
    protected static string $resource = EmployeePaymentVoucherResource::class;
}
