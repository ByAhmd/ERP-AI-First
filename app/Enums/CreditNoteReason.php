<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a credit note was issued.
 *
 * The four circumstances Article 40(1) of the KSA VAT Implementing Regulations
 * recognises. ZATCA publishes no code list for this — the field it lands in is
 * free text — so these are the platform's own, kept to the regulation's four so
 * a company cannot invent a fifth that has no legal footing.
 *
 * A reason is required on every credit note. It is not bookkeeping colour: the
 * regulation gives fifteen days from the end of the month in which the
 * triggering event occurred, and the event is what the reason describes.
 */
enum CreditNoteReason: string implements HasLabel
{
    case Cancellation = 'cancellation';
    case SupplyChange = 'supply_change';
    case PriceAdjustment = 'price_adjustment';
    case GoodsReturn = 'goods_return';

    public function getLabel(): string
    {
        return __("sales.credit_note_reason.{$this->value}");
    }
}
