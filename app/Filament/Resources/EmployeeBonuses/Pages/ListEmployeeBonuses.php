<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeBonuses\Pages;

use App\Filament\Resources\EmployeeBonuses\EmployeeBonusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeBonuses extends ListRecords
{
    protected static string $resource = EmployeeBonusResource::class;

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
