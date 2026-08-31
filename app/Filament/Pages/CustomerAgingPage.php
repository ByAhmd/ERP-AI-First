<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AgingReportPage;
use App\Services\Reports\AgingReportData;
use App\Services\Sales\Reports\CustomerAging;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;

/**
 * تقرير أعمار ديون العملاء.
 */
class CustomerAgingPage extends AgingReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return __('sales.customer_aging.title');
    }

    public function langBase(): string
    {
        return 'sales.customer_aging';
    }

    protected function build(CarbonImmutable $asOf, ?string $unit, int $periods): AgingReportData
    {
        return app(CustomerAging::class)->build($asOf, $unit, $periods);
    }

    public function getReconciliation(): ?array
    {
        $asOf = $this->primaryAsOf();

        return $asOf === null ? null : app(CustomerAging::class)->reconciliation($asOf);
    }
}
