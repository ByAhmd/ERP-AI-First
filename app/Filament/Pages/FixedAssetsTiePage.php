<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Assets\Reports\FixedAssetsTie;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * تقرير مطابقة سجل الأصول الثابتة.
 *
 * The register–GL tie: each asset and accumulated account beside the
 * register's own sum, with the difference in red when nonzero. Detection
 * for the enemies the module cannot block — pre-existing balances and
 * hand-keyed entries against the asset accounts.
 */
class FixedAssetsTiePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.fixed-assets-tie';

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.reports_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('assets.tie.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('assets.tie.title');
    }

    /**
     * @return array{rows: list<array<string, mixed>>, balanced: bool}
     */
    public function getReport(): array
    {
        return app(FixedAssetsTie::class)->build();
    }
}
