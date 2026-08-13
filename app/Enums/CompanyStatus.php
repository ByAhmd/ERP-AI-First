<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CompanyStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return __("enums.company_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Archived => 'gray',
        };
    }

    /**
     * Whether users may transact on behalf of a company in this state.
     *
     * Suspended and archived companies remain fully readable — financial records
     * must stay auditable after a subscription lapses — but accept no new postings.
     */
    public function allowsTransactions(): bool
    {
        return $this === self::Active;
    }
}
