<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a registered asset stands in its life.
 *
 * No draft state: a registered asset exists from the moment it is created,
 * exactly as in Qoyod. `Archived` is reserved for the return-and-archive and
 * merge flows of a later slice; nothing in this one sets it.
 */
enum FixedAssetStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Disposed = 'disposed';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return __("assets.status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Disposed => 'danger',
            self::Archived => 'gray',
        };
    }
}
