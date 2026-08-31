<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetDisposals\Pages;

use App\Enums\AssetDisposalKind;
use App\Filament\Resources\FixedAssetDisposals\FixedAssetDisposalResource;
use App\Services\Assets\AssetDisposalPoster;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateFixedAssetDisposal extends CreateRecord
{
    protected static string $resource = FixedAssetDisposalResource::class;

    /**
     * The reference is allocated at save, from the kind actually chosen —
     * sales and scraps number their own series.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();
        $data['created_by_id'] = Filament::auth()->id();

        $kind = AssetDisposalKind::tryFrom((string) ($data['kind'] ?? '')) ?? AssetDisposalKind::Sale;

        $data['reference'] = app(AssetDisposalPoster::class)->nextReference($kind);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * Arriving from an asset's Dispose button preselects the asset.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $assetId = request()->query('fixed_asset_id');

        if (is_string($assetId) && $assetId !== '' && blank($this->data['fixed_asset_id'] ?? null)) {
            $this->data['fixed_asset_id'] = $assetId;
        }
    }
}
