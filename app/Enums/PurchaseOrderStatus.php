<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\PurchaseOrder;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a purchase order stands in its own lifecycle.
 *
 * Deliberately not {@see DocumentStatus} — the QuotationStatus argument
 * verbatim: an order never posts, so an enum whose approved case answers
 * `affectsAccounts()` with yes would lie for it. Qoyod's cases: مسودة،
 * موافق عليه، تمت الفوترة، ملغي. Its متأخرة is derived from `expiry_date`
 * by {@see PurchaseOrder::isOverdue()}, never stored — a stored overdue
 * needs a scheduler to stay true. بانتظار الموافقة is reserved until
 * role-gated approval exists, as it is for quotations.
 */
enum PurchaseOrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Billed = 'billed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __("purchases.order_status.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'success',
            self::Billed => 'info',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Whether the order may still be changed.
     *
     * Approved is read-only — the order went to the supplier. Billed is
     * terminal: the order is frozen provenance for the bill pointing at it.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
