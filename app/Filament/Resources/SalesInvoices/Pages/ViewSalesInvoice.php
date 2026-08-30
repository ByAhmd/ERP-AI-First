<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesInvoices\Pages;

use App\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Models\SalesInvoice;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSalesInvoice extends ViewRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    /**
     * Where the invoice came from, when it came from somewhere.
     *
     * Qoyod shows no back-link at all — you find the invoice by searching the
     * customer. The provenance is stored here anyway, so it may as well be
     * said; discreetly, as a subheading rather than a field.
     */
    public function getSubheading(): string|Htmlable|null
    {
        /** @var SalesInvoice $invoice */
        $invoice = $this->getRecord();

        if ($invoice->quotation_id === null) {
            return null;
        }

        $reference = $invoice->quotation()->value('reference');

        return $reference === null ? null : __('sales.quotations.from_quotation', [
            'reference' => $reference,
        ]);
    }
}
