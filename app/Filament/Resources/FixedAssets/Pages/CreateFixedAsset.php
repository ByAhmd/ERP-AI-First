<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Assets\Exceptions\AssetRuleViolation;
use App\Services\Assets\FixedAssetRegistrar;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Creation goes through the registrar: reference allocation, the register
 * row and the acquisition entry share one transaction there.
 */
class CreateFixedAsset extends CreateRecord
{
    protected static string $resource = FixedAssetResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(FixedAssetRegistrar::class)->register($data, Filament::auth()->id());
        } catch (AssetRuleViolation|PostingRejected $refusal) {
            Notification::make()
                ->title($refusal->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
