<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What kind of thing is being sold or bought.
 *
 * The five Qoyod offers on the first step of its product wizard. The
 * distinction is not cosmetic: only some of them are stock, and stock is what
 * moves inventory and posts a cost of sale when it is invoiced.
 */
enum ProductType: string implements HasColor, HasLabel
{
    case Product = 'product';
    case Bundle = 'bundle';
    case RawMaterial = 'raw_material';
    case Service = 'service';
    case Expense = 'expense';

    public function getLabel(): string
    {
        return __("sales.product_type.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Product => 'success',
            self::Bundle => 'warning',
            self::RawMaterial => 'info',
            self::Service => 'primary',
            self::Expense => 'danger',
        };
    }

    /**
     * Whether quantities of this are held and counted.
     *
     * A service invoiced today consumes nothing from a warehouse, so it posts
     * revenue and no cost of sale. Getting this wrong understates gross margin
     * on everything a company sells.
     */
    public function isStocked(): bool
    {
        return match ($this) {
            self::Product, self::Bundle, self::RawMaterial => true,
            self::Service, self::Expense => false,
        };
    }
}
