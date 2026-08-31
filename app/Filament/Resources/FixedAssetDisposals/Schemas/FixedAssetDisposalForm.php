<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssetDisposals\Schemas;

use App\Enums\AssetDisposalKind;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\Tax;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The disposal form.
 *
 * The figures panel beside the fields answers Qoyod's disposal screen:
 * accumulated to date, the unregistered depreciation the approval will
 * force, and the book value — advisory here, resolved for real under the
 * lock at approval.
 */
class FixedAssetDisposalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('assets.disposals.sections.details'))
                ->schema([
                    Select::make('kind')
                        ->label(__('assets.disposals.fields.kind'))
                        ->options(AssetDisposalKind::class)
                        ->default(AssetDisposalKind::Sale)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),

                    Select::make('fixed_asset_id')
                        ->label(__('assets.disposals.fields.asset'))
                        ->options(fn (): array => FixedAsset::query()
                            ->where('status', 'active')
                            ->orderBy('reference')
                            ->get()
                            ->mapWithKeys(fn (FixedAsset $a): array => [
                                $a->getKey() => $a->reference.' — '.$a->displayName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->live(),

                    DatePicker::make('disposal_date')
                        ->label(__('assets.disposals.fields.date'))
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->live(onBlur: true),

                    TextInput::make('notes')
                        ->label(__('assets.disposals.fields.notes'))
                        ->maxLength(255),

                    Placeholder::make('figures')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->content(fn (Get $get): HtmlString => self::figures($get)),
                ])
                ->columns(2),

            Section::make(__('assets.disposals.sections.sale'))
                ->schema([
                    TextInput::make('proceeds')
                        ->label(__('assets.disposals.fields.proceeds'))
                        ->helperText(__('assets.disposals.hints.proceeds'))
                        ->numeric()
                        ->minValue(0)
                        ->required(fn (Get $get): bool => self::kindOf($get('kind')) === AssetDisposalKind::Sale),

                    Select::make('tax_id')
                        ->label(__('assets.disposals.fields.tax'))
                        ->helperText(__('assets.disposals.hints.tax'))
                        ->options(fn (): array => Tax::query()
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (Tax $t): array => [
                                $t->getKey() => $t->displayName(),
                            ])
                            ->all()),

                    Select::make('proceeds_account_id')
                        ->label(__('assets.disposals.fields.proceeds_account'))
                        ->options(fn (): array => Account::query()
                            ->where('is_payment_account', true)
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Account $a): array => [
                                $a->getKey() => $a->displayName(),
                            ])
                            ->all())
                        ->searchable()
                        ->required(fn (Get $get): bool => self::kindOf($get('kind')) === AssetDisposalKind::Sale),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => self::kindOf($get('kind')) === AssetDisposalKind::Sale),
        ]);
    }

    /**
     * The advisory figures for the chosen asset and date.
     */
    private static function figures(Get $get): HtmlString
    {
        $assetId = $get('fixed_asset_id');

        if (blank($assetId)) {
            return new HtmlString('');
        }

        $asset = FixedAsset::query()->find($assetId);

        if ($asset === null) {
            return new HtmlString('');
        }

        $accumulated = $asset->accumulatedDepreciation();
        $book = $asset->bookValue();

        $unposted = '0.0000';

        $date = $get('disposal_date');

        if (filled($date) && $asset->is_depreciable && $asset->useful_life_months !== null) {
            try {
                $preview = app(\App\Services\Assets\DepreciationEngine::class)->preview(
                    CarbonImmutable::parse((string) $date)->startOfDay(),
                    only: $asset,
                );

                $unposted = $preview['total'];
            } catch (\Throwable) {
                // Advisory only — a malformed date preview must not block
                // the form.
            }
        }

        $line = __('assets.disposals.hints.figures', [
            'cost' => number_format((float) $asset->cost, 2),
            'accumulated' => number_format((float) $accumulated, 2),
            'unposted' => number_format((float) $unposted, 2),
            'book' => number_format((float) bcsub($book, $unposted, 4), 2),
        ]);

        return new HtmlString(e($line));
    }

    private static function kindOf(mixed $state): AssetDisposalKind
    {
        if ($state instanceof AssetDisposalKind) {
            return $state;
        }

        return is_string($state)
            ? AssetDisposalKind::tryFrom($state) ?? AssetDisposalKind::Sale
            : AssetDisposalKind::Sale;
    }
}
