<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AgingReportPage;
use App\Services\Reports\AgingReportData;
use App\Services\Sales\Reports\QuotationAging;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;

/**
 * تقرير أعمار عروض الأسعار.
 */
class QuotationAgingPage extends AgingReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 70;

    public static function getNavigationLabel(): string
    {
        return __('sales.quotation_aging.title');
    }

    public function langBase(): string
    {
        return 'sales.quotation_aging';
    }

    protected function build(CarbonImmutable $asOf, ?string $unit, int $periods): AgingReportData
    {
        return app(QuotationAging::class)->build($asOf, $unit, $periods);
    }
}
