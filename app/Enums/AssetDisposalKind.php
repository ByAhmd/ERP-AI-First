<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How an asset leaves the books — Qoyod's بيع and تخريد.
 *
 * A sale carries proceeds and output VAT; a scrap writes the remaining book
 * value off as a loss. Each kind numbers its own series, matching Qoyod's
 * separate SE/SC references.
 */
enum AssetDisposalKind: string implements HasColor, HasLabel
{
    case Sale = 'sale';
    case Scrap = 'scrap';

    public function getLabel(): string
    {
        return __("assets.disposal_kind.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sale => 'info',
            self::Scrap => 'warning',
        };
    }
}
