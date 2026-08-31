<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\BalancesSummaryPage;
use App\Services\Reports\BalancesSummary;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * تقرير ملخص مستحقات الموردين.
 */
class SupplierBalancesSummaryPage extends BalancesSummaryPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 65;

    public static function getNavigationLabel(): string
    {
        return __('purchases.supplier_balances.title');
    }

    public function langBase(): string
    {
        return 'purchases.supplier_balances';
    }

    public function getReport(): array
    {
        $asOf = $this->asOf();

        return $asOf === null
            ? ['rows' => [], 'totals' => ['open_invoices' => '0.0000', 'unapplied_notes' => '0.0000', 'unused_vouchers' => '0.0000', 'net' => '0.0000']]
            : app(BalancesSummary::class)->suppliers($asOf);
    }
}
