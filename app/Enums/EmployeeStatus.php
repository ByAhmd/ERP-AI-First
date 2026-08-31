<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an employee stands — Qoyod's tabs: active, المنتهية خدمتهم,
 * archived.
 *
 * Termination in this slice is a date and a status; the end-of-service
 * arithmetic ships with the EOS slice.
 */
enum EmployeeStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Terminated = 'terminated';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return __("payroll.employee_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Terminated => 'warning',
            self::Archived => 'gray',
        };
    }
}
