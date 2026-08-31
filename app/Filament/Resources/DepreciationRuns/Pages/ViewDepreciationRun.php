<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepreciationRuns\Pages;

use App\Filament\Resources\DepreciationRuns\DepreciationRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDepreciationRun extends ViewRecord
{
    protected static string $resource = DepreciationRunResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            DepreciationRunResource::reverseAction()
                ->record($this->getRecord()),
        ];
    }
}
