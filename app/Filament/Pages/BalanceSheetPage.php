<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\FinancialStatementPage;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\FinancialStatement;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The balance sheet.
 *
 * Bounded by a single date, because a position has no start — every figure on
 * it accumulates from the company's first entry.
 */
class BalanceSheetPage extends FinancialStatementPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('accounting.statements.balance_sheet.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.statements.balance_sheet.title');
    }

    public function getSubheading(): ?string
    {
        return __('accounting.statements.balance_sheet.subheading');
    }

    public function getStatement(): FinancialStatement
    {
        $asOf = $this->filters['as_of'] ?? null;

        return app(BalanceSheet::class)->build(
            asOf: blank($asOf) ? CarbonImmutable::now() : CarbonImmutable::parse($asOf),
            options: $this->options(),
        );
    }

    /**
     * @return list<DatePicker>
     */
    protected function dateComponents(): array
    {
        return [
            DatePicker::make('as_of')
                ->label(__('accounting.statements.as_of'))
                ->native(false)
                ->required()
                ->live(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultFilters(): array
    {
        return ['as_of' => CarbonImmutable::now()->toDateString()];
    }
}
