<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AgingReportPage;
use App\Services\Purchases\Reports\PurchaseOrderAging;
use App\Services\Reports\AgingReportData;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;

/**
 * تقرير أعمار أوامر الشراء.
 */
class PurchaseOrderAgingPage extends AgingReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 80;

    public static function getNavigationLabel(): string
    {
        return __('purchases.order_aging.title');
    }

    public function langBase(): string
    {
        return 'purchases.order_aging';
    }

    protected function build(CarbonImmutable $asOf, ?string $unit, int $periods): AgingReportData
    {
        return app(PurchaseOrderAging::class)->build($asOf, $unit, $periods);
    }
}
