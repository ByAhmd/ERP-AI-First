<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Models\Company;
use App\Services\Reports\AgingReportData;
use App\Services\Reports\ComparisonPeriods;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The shape all four aging reports share.
 *
 * Qoyod's aging reports are one report rendered four times: an as-of date, an
 * optional comparison unit with up to thirteen prior-period columns, a grid
 * of contacts, a totals row. Subclasses supply the service call, the lang
 * base, and — for the two debt reports — the reconciliation footer.
 *
 * The default as-of date is "today" in the COMPANY's timezone, not UTC: the
 * app stores UTC, companies default to Asia/Riyadh, and a plain now() between
 * midnight and three in Riyadh would open every report on yesterday.
 *
 * `$form` is resolved by Livewire's dynamic property handling, which static
 * analysis cannot see.
 *
 * @property-read Schema $form
 */
abstract class AgingReportPage extends Page
{
    protected string $view = 'filament.pages.aging-report';

    /**
     * @var array<string, mixed>
     */
    public array $filters = [];

    /**
     * The lang block this report reads its wording from.
     */
    abstract public function langBase(): string;

    abstract protected function build(CarbonImmutable $asOf, ?string $unit, int $periods): AgingReportData;

    /**
     * The reconciliation lines under the grid — the two debt reports
     * override; the document reports have nothing to reconcile to.
     *
     * @return ?array{unapplied_notes: string, advances: string}
     */
    public function getReconciliation(): ?array
    {
        return null;
    }

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
            'compare_unit' => null,
            'periods' => 3,
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

                        Select::make('compare_unit')
                            ->label(__($this->langBase().'.compare_with'))
                            ->options([
                                'year' => __($this->langBase().'.units.year'),
                                'quarter' => __($this->langBase().'.units.quarter'),
                                'month' => __($this->langBase().'.units.month'),
                                'week' => __($this->langBase().'.units.week'),
                            ])
                            ->placeholder(__($this->langBase().'.no_comparison'))
                            ->live(),

                        Select::make('periods')
                            ->label(__($this->langBase().'.periods'))
                            ->options(array_combine(
                                range(1, ComparisonPeriods::MAX_PERIODS),
                                array_map(strval(...), range(1, ComparisonPeriods::MAX_PERIODS)),
                            ))
                            ->visible(fn (): bool => filled($this->filters['compare_unit'] ?? null))
                            ->live(),
                    ])
                    ->columns(3),
            ]);
    }

    public function getData(): AgingReportData
    {
        $asOf = $this->filters['as_of'] ?? null;

        if (blank($asOf)) {
            return new AgingReportData(dates: [], rows: [], totals: []);
        }

        $unit = $this->filters['compare_unit'] ?? null;

        return $this->build(
            CarbonImmutable::parse((string) $asOf),
            filled($unit) && is_string($unit) ? $unit : null,
            (int) ($this->filters['periods'] ?? 1),
        );
    }

    public function primaryAsOf(): ?CarbonImmutable
    {
        $asOf = $this->filters['as_of'] ?? null;

        return blank($asOf) ? null : CarbonImmutable::parse((string) $asOf);
    }
}
