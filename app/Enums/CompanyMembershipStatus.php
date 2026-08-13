<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CompanyMembershipStatus: string implements HasColor, HasLabel
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return __("enums.membership_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Invited => 'info',
            self::Suspended => 'danger',
        };
    }

    /**
     * Whether this membership permits the user to select and act for the company.
     *
     * Only active members may. Invited users have not accepted yet; suspended
     * members are retained so that their audit history stays attributable.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
