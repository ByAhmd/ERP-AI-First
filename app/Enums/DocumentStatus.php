<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a sales document stands in its own lifecycle.
 *
 * Shared by every document that posts — invoices, credit notes, and the
 * receipts and quotations that follow — because the lifecycle is the same for
 * all of them and three parallel enums saying `draft` would be three places to
 * forget a case.
 *
 * Qoyod states the distinction in its own help text: `حفظ كمسودة` stores a
 * document that affects neither the accounts nor the reports, and
 * `حفظ وموافقة` makes it final. That is the same line `JournalPoster` already
 * draws between a draft and a posted entry, so the document does not invent a
 * second notion of "not yet real".
 *
 * This is the document's lifecycle only. How much of it has been paid is a
 * different question, answered by the receipts against it rather than stored
 * here — an invoice does not become a different document when a payment
 * arrives.
 */
enum DocumentStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Void = 'void';

    public function getLabel(): string
    {
        return __("sales.document_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'success',
            self::Void => 'danger',
        };
    }

    /**
     * Whether the invoice may still be changed.
     *
     * An approved invoice has reached the ledger and the customer. Correction
     * is by credit note, which leaves both documents visible, rather than by
     * editing history.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the invoice contributes to the ledger and to reports.
     */
    public function affectsAccounts(): bool
    {
        return $this === self::Approved;
    }
}
