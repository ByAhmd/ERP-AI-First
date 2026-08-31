<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Payroll\Reports\PayrollTie;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * تقرير مطابقة الرواتب.
 *
 * The payable and the advances against their subledgers, with the GOSI
 * liability reported informationally — settlements are manual entries in
 * this slice.
 */
class PayrollTiePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.payroll-tie';

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.reports_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('payroll.tie.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('payroll.tie.title');
    }

    /**
     * @return array{rows: list<array<string, mixed>>, balanced: bool}
     */
    public function getReport(): array
    {
        return app(PayrollTie::class)->build();
    }
}
