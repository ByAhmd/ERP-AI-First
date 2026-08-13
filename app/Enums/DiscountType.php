<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a discount on a document line is expressed.
 *
 * Qoyod carries both a `discount_percentage` and a `discount_type` on every
 * invoice line, which is the pair this represents: the same number means ten
 * percent or ten riyals depending on which is chosen.
 */
enum DiscountType: string implements HasLabel
{
    case Percentage = 'percentage';
    case Amount = 'amount';

    public function getLabel(): string
    {
        return __("sales.invoices.discount_type.{$this->value}");
    }
}
