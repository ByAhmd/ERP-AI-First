<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Services\Accounting\Reports\TrialBalance;
use App\Services\Accounting\Reports\TrialBalanceRow;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * The trial balance.
 *
 * Its one job is to show whether debits equal credits. The totals row states
 * that plainly rather than leaving the reader to add up columns, because an
 * out-of-balance ledger is the single condition that invalidates every other
 * report.
 *
 * `$form` is resolved by Livewire's dynamic property handling, which static
 * analysis cannot see. Declared here rather than replaced with getSchema(),
 * because property access is Filament's documented API for pages.
 *
 * @property-read Schema $form
 */
class TrialBalancePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.trial-balance';

    /**
     * @var array<string, mixed>
     */
    public array $filters = [];

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.reports_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting.trial_balance.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.trial_balance.title');
    }

    public function mount(): void
    {
        // Defaults to the current fiscal year to date, which is what someone
        // opening this report almost always wants.
        $this->form->fill([
            'from' => CarbonImmutable::now()->startOfYear()->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
            'include_empty' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
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

                        Select::make('branch_id')
                            ->label(__('accounting.entries.columns.branch'))
                            ->options(fn (): array => Branch::query()
                                ->orderBy('code')->pluck('name', 'id')->all())
                            ->placeholder(__('accounting.trial_balance.all_branches'))
                            ->live(),

                        Toggle::make('include_empty')
                            ->label(__('accounting.trial_balance.include_empty'))
                            ->helperText(__('accounting.trial_balance.include_empty_hint'))
                            ->live(),
                    ])
                    ->columns(4),
            ]);
    }

    /**
     * @return Collection<int, TrialBalanceRow>
     */
    public function getRows(): Collection
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        if (blank($from) || blank($to)) {
            return collect();
        }

        return app(TrialBalance::class)->build(
            from: CarbonImmutable::parse($from),
            to: CarbonImmutable::parse($to),
            filters: [
                'branch_id' => $this->filters['branch_id'] ?? null,
                'dimension_value_id' => $this->filters['dimension_value_id'] ?? null,
            ],
            includeEmpty: (bool) ($this->filters['include_empty'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getTotals(): array
    {
        return app(TrialBalance::class)->totals($this->getRows());
    }
}
