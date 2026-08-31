<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Models\Company;
use App\Services\Reports\BalancesSummaryRow;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The shape both balances summary reports share — ملخص المستحقات.
 *
 * One as-of date, one grid: open documents, unapplied notes, unused
 * vouchers, and the net per contact. Subclasses pick the side.
 *
 * @property-read Schema $form
 */
abstract class BalancesSummaryPage extends Page
{
    protected string $view = 'filament.pages.balances-summary';

    /**
     * @var array<string, mixed>
     */
    public array $filters = [];

    abstract public function langBase(): string;

    /**
     * @return array{rows: list<BalancesSummaryRow>, totals: array<string, string>}
     */
    abstract public function getReport(): array;

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.reports_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __($this->langBase().'.title');
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        $timezone = $tenant instanceof Company ? $tenant->timezone : 'Asia/Riyadh';

        $this->form->fill([
            'as_of' => CarbonImmutable::now($timezone)->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
                        DatePicker::make('as_of')
                            ->label(__($this->langBase().'.as_of'))
                            ->native(false)
                            ->required()
                            ->live(),
                    ])
                    ->columns(3),
            ]);
    }

    protected function asOf(): ?CarbonImmutable
    {
        $asOf = $this->filters['as_of'] ?? null;

        return blank($asOf) ? null : CarbonImmutable::parse((string) $asOf);
    }
}
