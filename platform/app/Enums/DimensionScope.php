<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How widely a dimension applies.
 *
 * Follows Qoyod's distinction. A general dimension is system-wide: it applies to
 * every document, feeds the consolidated reports, and cannot be tailored per
 * document type. A specific dimension is narrower and is opted into.
 */
enum DimensionScope: string implements HasColor, HasDescription, HasLabel
{
    case General = 'general';
    case Specific = 'specific';

    /**
     * Qoyod permits at most two general dimensions per company.
     *
     * The cap is what keeps a general dimension meaningful: every document
     * carries every general dimension, so an unbounded number would mean an
     * unbounded number of mandatory tags on each line.
     */
    public const GENERAL_LIMIT = 2;

    public function getLabel(): string
    {
        return __("accounting.dimension_scope.{$this->value}");
    }

    public function getDescription(): string
    {
        return __("accounting.dimension_scope.{$this->value}_description");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::General => 'primary',
            self::Specific => 'gray',
        };
    }

    public function isGeneral(): bool
    {
        return $this === self::General;
    }
}
