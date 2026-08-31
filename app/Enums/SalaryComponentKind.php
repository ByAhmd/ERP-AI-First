<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a salary component does to the slip: adds or subtracts.
 */
enum SalaryComponentKind: string implements HasColor, HasLabel
{
    case Allowance = 'allowance';
    case Deduction = 'deduction';

    public function getLabel(): string
    {
        return __("payroll.component_kind.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Allowance => 'success',
            self::Deduction => 'warning',
        };
    }
}
