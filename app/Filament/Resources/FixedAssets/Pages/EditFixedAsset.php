<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Models\FixedAsset;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFixedAsset extends EditRecord
{
    protected static string $resource = FixedAssetResource::class;

    /**
     * A disposed asset is history — reading only.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var FixedAsset $asset */
        $asset = $this->getRecord();

        if (! $asset->isActive()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $asset]));
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Hard delete only while nothing ever posted for the asset —
            // Qoyod's rule: delete the linked operations first, or never.
            DeleteAction::make()
                ->visible(function (): bool {
                    /** @var FixedAsset $asset */
                    $asset = $this->getRecord();

                    return ! $asset->isFinanciallyLocked();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
