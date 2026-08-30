<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\FinancialStatementPage;
use App\Services\Accounting\Reports\FinancialStatement;
use App\Services\Accounting\Reports\IncomeStatement;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The income statement.
 *
 * Bounded at both ends, because it measures what happened between two dates
 * rather than where things stand on one.
 */
class IncomeStatementPage extends FinancialStatementPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('accounting.statements.income_statement.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.statements.income_statement.title');
    }

    public function getSubheading(): ?string
    {
        return __('accounting.statements.income_statement.subheading');
    }

    public function getStatement(): FinancialStatement
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        $end = blank($to) ? CarbonImmutable::now() : CarbonImmutable::parse($to);
        $start = blank($from) ? $end->startOfYear() : CarbonImmutable::parse($from);

        return app(IncomeStatement::class)->build(
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
        // The fiscal year to date, which is what someone opening this report
        // almost always wants.
        return [
            'from' => CarbonImmutable::now()->startOfYear()->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
        ];
    }
}
