<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeDeductions\Pages;

use App\Filament\Resources\EmployeeDeductions\EmployeeDeductionResource;
use App\Models\EmployeeDeduction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeDeduction extends EditRecord
{
    protected static string $resource = EmployeeDeductionResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var EmployeeDeduction $deduction */
        $deduction = $this->getRecord();

        if (! $deduction->isDraft()) {
            $this->redirect($this->getResource()::getUrl('index'));
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
