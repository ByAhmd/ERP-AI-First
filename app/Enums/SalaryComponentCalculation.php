<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a component's amount is read: a fixed monthly figure, or a
 * percentage of the employee's (prorated) base salary.
 */
enum SalaryComponentCalculation: string implements HasLabel
{
    case Fixed = 'fixed';
    case PercentOfBase = 'percent_of_base';

    public function getLabel(): string
    {
        return __("payroll.calculation.{$this->value}");
    }
}
