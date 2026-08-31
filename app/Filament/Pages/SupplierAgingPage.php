<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AgingReportPage;
use App\Services\Purchases\Reports\SupplierAging;
use App\Services\Reports\AgingReportData;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;

/**
 * تقرير أعمار ديون الموردين.
 */
class SupplierAgingPage extends AgingReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        return __('purchases.supplier_aging.title');
    }

    public function langBase(): string
    {
        return 'purchases.supplier_aging';
    }

    protected function build(CarbonImmutable $asOf, ?string $unit, int $periods): AgingReportData
    {
        return app(SupplierAging::class)->build($asOf, $unit, $periods);
    }

    public function getReconciliation(): ?array
    {
        $asOf = $this->primaryAsOf();

        return $asOf === null ? null : app(SupplierAging::class)->reconciliation($asOf);
    }
}
