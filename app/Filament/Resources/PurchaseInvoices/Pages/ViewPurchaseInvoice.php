<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInvoices\Pages;

use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewPurchaseInvoice extends ViewRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    /**
     * Where the bill came from, when it came from somewhere — said
     * discreetly, as a subheading rather than a field.
     */
    public function getSubheading(): string|Htmlable|null
    {
        /** @var PurchaseInvoice $invoice */
        $invoice = $this->getRecord();

        if ($invoice->purchase_order_id === null) {
            return null;
        }

        $reference = $invoice->order()->value('reference');

        return $reference === null ? null : __('purchases.orders.from_order', [
            'reference' => $reference,
        ]);
    }
}
