<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\FinancialStatementPage;
use App\Services\Accounting\Reports\FinancialStatement;
use App\Services\Accounting\Reports\StatementOfChangesInEquity;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The statement of changes in equity.
 *
 * How ownership moved across the period: what was there at the start, what
 * profit added, what capital or drawings changed, and what remains.
 */
class StatementOfChangesInEquityPage extends FinancialStatementPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?int $navigationSort = 17;

    public static function getNavigationLabel(): string
    {
        return __('accounting.statements.equity_changes.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.statements.equity_changes.title');
    }

    public function getSubheading(): ?string
    {
        return __('accounting.statements.equity_changes.subheading');
    }

    public function getStatement(): FinancialStatement
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        $end = blank($to) ? CarbonImmutable::now() : CarbonImmutable::parse($to);
        $start = blank($from) ? $end->startOfYear() : CarbonImmutable::parse($from);

        return app(StatementOfChangesInEquity::class)->build(
            from: $start,
            to: $end,
            options: $this->options(),
        );
    }

    /**
     * @return list<DatePicker>
     */
    protected function dateComponents(): array
    {
        return [
            DatePicker::make('from')
                ->label(__('accounting.trial_balance.from'))
                ->native(false)
                ->required()
                ->live(),

            DatePicker::make('to')
                ->label(__('accounting.trial_balance.to'))
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
        return [
            'from' => CarbonImmutable::now()->startOfYear()->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
        ];
    }
}
