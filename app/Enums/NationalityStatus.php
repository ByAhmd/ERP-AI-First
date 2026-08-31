<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Saudi or not — the axis GOSI contribution rates swing on: Saudis carry
 * both shares, non-Saudis only the employer's occupational-hazard share.
 */
enum NationalityStatus: string implements HasLabel
{
    case Saudi = 'saudi';
    case NonSaudi = 'non_saudi';

    public function getLabel(): string
    {
        return __("payroll.nationality.{$this->value}");
    }
}
