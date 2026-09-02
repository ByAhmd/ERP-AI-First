<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\FinancialStatementPage;
use App\Services\Accounting\Reports\CashFlowStatement;
use App\Services\Accounting\Reports\FinancialStatement;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The cash flow statement.
 *
 * Indirect method, as Qoyod renders it: start from the result before
 * financing and statutory charges, walk back non-cash items and working-capital
 * movements, then read investing and financing from the balance sheet.
 */
class CashFlowStatementPage extends FinancialStatementPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 15;

    public static function getNavigationLabel(): string
    {
        return __('accounting.statements.cash_flow.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.statements.cash_flow.title');
    }

    public function getSubheading(): ?string
    {
        return __('accounting.statements.cash_flow.subheading');
    }

    public function getStatement(): FinancialStatement
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        $end = blank($to) ? CarbonImmutable::now() : CarbonImmutable::parse($to);
        $start = blank($from) ? $end->startOfYear() : CarbonImmutable::parse($from);

        return app(CashFlowStatement::class)->build(
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
