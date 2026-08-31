<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryTransfers\Pages;

use App\Filament\Resources\InventoryTransfers\InventoryTransferResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryTransfer extends ViewRecord
{
    protected static string $resource = InventoryTransferResource::class;
}
