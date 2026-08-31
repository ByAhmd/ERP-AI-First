<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Whether an employee's salary is direct cost or overhead — Qoyod's
 * تكلفة مباشرة / غير مباشرة on the employment tab. Decides which expense
 * account the run debits for the base salary.
 */
enum EmployeeCostType: string implements HasLabel
{
    case Direct = 'direct';
    case Indirect = 'indirect';

    public function getLabel(): string
    {
        return __("payroll.cost_type.{$this->value}");
    }
}
