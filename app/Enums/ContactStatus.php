<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a contact may be used on a new document.
 *
 * Deactivating rather than deleting is the only safe way to retire a party a
 * company has traded with: the invoices remain, and they have to keep naming
 * someone.
 */
enum ContactStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return __("sales.contact_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
        };
    }

    public function acceptsNewDocuments(): bool
    {
        return $this === self::Active;
    }
}
