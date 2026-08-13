<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a supply is treated for VAT.
 *
 * The single-letter values are not shorthand this platform invented. They are
 * the tax category codes ZATCA requires on an electronic invoice, taken from
 * UN/CEFACT 5305, and Qoyod exposes exactly these as the `الرمز` column on a
 * tax. Storing the code rather than deriving it from the rate matters: a
 * zero-rated export and an exempt supply are both 0%, and an invoice that
 * cannot tell them apart cannot be filed.
 */
enum TaxCategory: string implements HasColor, HasLabel
{
    case Standard = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';

    public function getLabel(): string
    {
        return __("sales.tax_category.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Standard => 'success',
            self::ZeroRated => 'info',
            self::Exempt => 'gray',
        };
    }

    /**
     * Whether a rate above zero is meaningful for this category.
     *
     * Zero-rated and exempt supplies are defined by carrying no tax. A rate
     * entered against either is a mistake rather than a preference.
     */
    public function allowsRate(): bool
    {
        return $this === self::Standard;
    }
}
