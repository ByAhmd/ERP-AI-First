<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a stock adjustment is for.
 *
 * Opening is the one bridge from a pre-inventory life into tracking: it
 * posts against the opening-balance suspense and is the only way stock
 * enters without a purchase. Count is the الجرد variance — what the shelf
 * says against what the system said.
 */
enum StockAdjustmentKind: string implements HasColor, HasLabel
{
    case Opening = 'opening';
    case Count = 'count';

    public function getLabel(): string
    {
        return __("inventory.adjustment_kind.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Opening => 'info',
            self::Count => 'warning',
        };
    }
}
