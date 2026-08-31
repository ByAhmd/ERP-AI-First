<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Qoyod's bonus kinds: عمولة، منحة، أخرى.
 */
enum BonusKind: string implements HasLabel
{
    case Commission = 'commission';
    case Grant = 'grant';
    case Other = 'other';

    public function getLabel(): string
    {
        return __("payroll.bonus_kind.{$this->value}");
    }
}
