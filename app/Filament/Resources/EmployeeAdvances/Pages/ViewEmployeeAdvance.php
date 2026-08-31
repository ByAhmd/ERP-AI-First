<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeeAdvance extends ViewRecord
{
    protected static string $resource = EmployeeAdvanceResource::class;
}
