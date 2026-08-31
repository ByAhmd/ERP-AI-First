<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepreciationRuns\Pages;

use App\Filament\Resources\DepreciationRuns\DepreciationRunResource;
use App\Models\AccountingPeriod;
use App\Models\FixedAsset;
use App\Models\FixedAssetType;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Assets\DepreciationEngine;
use App\Services\Assets\Exceptions\RunRejected;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDepreciationRuns extends ListRecords
{
    protected static string $resource = DepreciationRunResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')
                ->label(__('assets.runs.actions.run'))
                ->icon(Heroicon::OutlinedPlayCircle)
                ->color('primary')
                ->schema([
                    Select::make('through_period_id')
                        ->label(__('assets.runs.fields.through_period'))
                        ->helperText(__('assets.runs.hints.through_period'))
                        ->options(fn (): array => AccountingPeriod::query()
                            ->orderByDesc('start_date')
                            ->limit(36)
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): ?string => AccountingPeriod::query()
                            ->whereDate('start_date', '<=', now())
                            ->whereDate('end_date', '>=', now())
                            ->value('id'))
                        ->required(),

                    Select::make('fixed_asset_type_id')
                        ->label(__('assets.runs.fields.type'))
                        ->placeholder(__('assets.runs.fields.all_types'))
                        ->options(fn (): array => FixedAssetType::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),

                    Select::make('fixed_asset_id')
                        ->label(__('assets.runs.fields.asset'))
                        ->placeholder(__('assets.runs.fields.all_assets'))
                        ->options(fn (): array => FixedAsset::query()
                            ->where('status', 'active')
                            ->where('is_depreciable', true)
                            ->orderBy('reference')
                            ->get()
                            ->mapWithKeys(fn (FixedAsset $a): array => [
                                $a->getKey() => $a->reference.' — '.$a->displayName(),
                            ])
                            ->all())
                        ->searchable(),
                ])
                ->action(function (array $data, DepreciationEngine $engine): void {
                    $period = AccountingPeriod::query()->findOrFail($data['through_period_id']);

                    $type = filled($data['fixed_asset_type_id'] ?? null)
                        ? FixedAssetType::query()->findOrFail($data['fixed_asset_type_id'])
                        : null;

                    $asset = filled($data['fixed_asset_id'] ?? null)
                        ? FixedAsset::query()->findOrFail($data['fixed_asset_id'])
                        : null;

                    try {
                        $run = $engine->run(
                            CarbonImmutable::instance($period->end_date)->startOfDay(),
                            $type,
                            $asset,
                            Filament::auth()->id(),
                        );
                    } catch (RunRejected|PostingRejected $refusal) {
                        Notification::make()
                            ->title($refusal->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('assets.runs.actions.ran', [
                            'reference' => $run->reference,
                            'total' => number_format((float) $run->total_amount, 2),
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
