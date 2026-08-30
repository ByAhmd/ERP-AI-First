<?php

declare(strict_types=1);

namespace App\Filament\Resources\SimplePurchaseInvoices\Pages;

use App\Filament\Resources\SimplePurchaseInvoices\SimplePurchaseInvoiceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSimplePurchaseInvoice extends ViewRecord
{
    protected static string $resource = SimplePurchaseInvoiceResource::class;
}
