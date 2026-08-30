<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which face of the bill a purchase invoice wears.
 *
 * One table, two screens — the split Contact already makes between customers
 * and suppliers. A standard bill carries products, quantities and a due date;
 * a simple bill (فاتورة بسيطة) is a quick expense keyed straight to accounts.
 * Both post identically and both must appear in every payable query, which is
 * why this is a column and not a second table.
 */
enum PurchaseInvoiceKind: string
{
    case Standard = 'standard';
    case Simple = 'simple';
}
