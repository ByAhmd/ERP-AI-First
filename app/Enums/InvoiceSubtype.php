<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Contact;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Standard or simplified tax invoice.
 *
 * The first two digits of ZATCA's invoice type transaction code (KSA-2), and a
 * Phase 1 requirement: the printed document must say which it is, because the
 * two are different legal instruments. A standard tax invoice (01) identifies
 * the buyer and is what a VAT-registered customer needs to recover input VAT;
 * a simplified one (02) is for consumers, who need not be identified.
 *
 * The values are ZATCA's own codes, stored as-is so serialising KSA-2 later is
 * assembly rather than translation.
 */
enum InvoiceSubtype: string implements HasColor, HasLabel
{
    case Standard = '01';
    case Simplified = '02';

    public function getLabel(): string
    {
        return __("sales.invoice_subtype.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Standard => 'info',
            self::Simplified => 'gray',
        };
    }

    /**
     * The full seven-character KSA-2 code, `NNPNESB`.
     *
     * The five flags — third-party, nominal, export, summary, self-billed —
     * are all zero because nothing in the platform can produce those supplies
     * yet. When one lands, it becomes a column and this becomes assembly; the
     * subtype prefix is the part that is the user's choice.
     */
    public function transactionCode(): string
    {
        return $this->value.'00000';
    }

    /**
     * The default for a customer.
     *
     * A VAT-registered buyer needs a standard invoice — a simplified one gives
     * them nothing to recover input VAT with. An unregistered consumer gets a
     * simplified one, which is what it exists for.
     */
    public static function forContact(?Contact $contact): self
    {
        return $contact !== null && $contact->isTaxRegistered()
            ? self::Standard
            : self::Simplified;
    }
}
