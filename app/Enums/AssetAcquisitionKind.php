<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How an asset entered the register.
 *
 * `Opening` covers balances the company arrived with — registered against an
 * existing ledger balance or posted against the opening-balance suspense.
 * `Purchase` is a manual purchase recorded inside the module, credited to a
 * payment account. `Bill` is reserved for the from-bill capitalization slice;
 * nothing creates it yet.
 */
enum AssetAcquisitionKind: string implements HasLabel
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Bill = 'bill';

    public function getLabel(): string
    {
        return __("assets.acquisition_kind.{$this->value}");
    }
}
