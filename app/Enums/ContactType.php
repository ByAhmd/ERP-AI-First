<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Which side of the business a contact sits on.
 *
 * Customers and suppliers share one record in Qoyod — both menu items point at
 * the same `/tenant/contacts` — and they share one here for the same reason:
 * the fields are identical, and a company that both buys from and sells to the
 * same party should not have to maintain its tax number twice.
 */
enum ContactType: string implements HasColor, HasLabel
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    public function getLabel(): string
    {
        return __("sales.contact_type.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Customer => 'success',
            self::Supplier => 'info',
        };
    }

    /**
     * The prefix its reference number is generated under.
     *
     * `CUS` is what Qoyod generates — the customer form opens on `CUS001`.
     * `VEN` is inferred: the supplier list is gated behind a higher plan on the
     * tenant this was read from, so it could not be confirmed.
     */
    public function referencePrefix(): string
    {
        return match ($this) {
            self::Customer => 'CUS',
            self::Supplier => 'VEN',
        };
    }

    /**
     * The control account a balance for this contact belongs to.
     */
    public function controlAccount(): SystemAccount
    {
        return match ($this) {
            self::Customer => SystemAccount::AccountsReceivable,
            self::Supplier => SystemAccount::AccountsPayable,
        };
    }
}
