<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeBonuses\Pages;

use App\Filament\Resources\EmployeeBonuses\EmployeeBonusResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeeBonus extends ViewRecord
{
    protected static string $resource = EmployeeBonusResource::class;
}
