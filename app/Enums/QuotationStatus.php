<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SalesQuotation;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a quotation stands in its own lifecycle.
 *
 * Deliberately not {@see DocumentStatus}. That enum belongs to documents that
 * post — its own docblock says so — and its `affectsAccounts()` would return
 * true for an approved quotation, asserting an accounting event that never
 * happened. A quotation is a commercial document: Qoyod's own help calls its
 * reporting "تجاري وتحليلي، وليس محاسبي". Two enums both spelling `draft` is
 * cheaper than one enum whose answers must grow a caller-knows-best exception.
 *
 * The four cases are Qoyod's, verbatim: مسودة، موافق عليه، تم الفوترة، ملغي.
 * Qoyod has a fifth — بانتظار الموافقة, for a user without the approve
 * permission — reserved here until role-gated approval arrives for invoices
 * and quotations together; today any user may approve either, so the case
 * would be unreachable.
 *
 * "Expired" is not a case. It is derived from `expiry_date` and the clock by
 * {@see SalesQuotation::isExpired()} — a stored expired status
 * needs a scheduler to flip it, and a scheduler that silently was not running
 * is how a March price survives into June.
 */
enum QuotationStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __("sales.quotation_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'success',
            self::Invoiced => 'info',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Whether the quotation may still be changed.
     *
     * Approved is already read-only: the customer holds the offer. Invoiced is
     * terminal — the quotation is frozen provenance for the invoice that
     * points at it, and editing it would let the audit trail show an invoice
     * "created from" a document that no longer says what it said.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
