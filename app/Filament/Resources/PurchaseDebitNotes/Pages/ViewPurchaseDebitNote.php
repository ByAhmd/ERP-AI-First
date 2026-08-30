<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseDebitNotes\Pages;

use App\Filament\Resources\PurchaseDebitNotes\PurchaseDebitNoteResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseDebitNote extends ViewRecord
{
    protected static string $resource = PurchaseDebitNoteResource::class;
}
