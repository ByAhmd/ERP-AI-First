<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeAdvances extends ListRecords
{
    protected static string $resource = EmployeeAdvanceResource::class;

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
