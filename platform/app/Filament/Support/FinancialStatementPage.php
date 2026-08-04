<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\ComparisonInterval;
use App\Models\Branch;
use App\Services\Accounting\Reports\FinancialStatement;
use App\Services\Accounting\Reports\StatementOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Shared behaviour for the balance sheet and the income statement.
 *
 * The two differ in one thing a reader can name — position on a date against
 * movement across a period — and in nothing else. They offer the same
 * narrowing, the same comparison columns, the same account depth, and they
 * render identically. Holding that here means a change to any of it lands on
 * both, and the two cannot drift into looking like different products.
 *
 * Deliberately outside the directory Filament discovers pages in: discovery
 * would otherwise have to be trusted to skip an abstract class, and a base
 * class appearing in the navigation is a confusing way to find that out.
 *
 * `$form` is resolved by Livewire's dynamic property handling, which static
 * analysis cannot see. Declared here rather than replaced with getSchema(),
 * because property access is Filament's documented API for pages.
 *
 * @property-read Schema $form
 */
abstract class FinancialStatementPage extends Page
{
    protected string $view = 'filament.pages.financial-statement';

    /**
     * @var array<string, mixed>
     */
    public array $filters = [];

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.reports_group');
    }

    /**
     * The statement as currently filtered.
     */
    abstract public function getStatement(): FinancialStatement;

    /**
     * Whichever dates this statement is bounded by.
     *
     * @return list<DatePicker>
     */
    abstract protected function dateComponents(): array;

    /**
     * The defaults a reader sees before touching anything.
     *
     * @return array<string, mixed>
     */
    abstract protected function defaultFilters(): array;

    public function mount(): void
    {
        $this->form->fill([
            ...$this->defaultFilters(),
            'interval' => ComparisonInterval::None->value,
            'comparisons' => 1,
            'depth' => StatementOptions::DEFAULT_DEPTH,
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
                        ...$this->dateComponents(),

                        Select::make('interval')
                            ->label(__('accounting.comparison.compare_with'))
                            ->options(ComparisonInterval::class)
                            ->default(ComparisonInterval::None->value)
                            ->selectablePlaceholder(false)
                            ->live(),

                        Select::make('comparisons')
                            ->label(__('accounting.comparison.columns'))
                            ->options(fn (): array => array_combine(
                                range(1, ComparisonInterval::Month->maximumComparisons()),
                                range(1, ComparisonInterval::Month->maximumComparisons()),
                            ))
                            ->selectablePlaceholder(false)
                            // Meaningless without something to compare against,
                            // and a count that does nothing invites the reader
                            // to conclude the report is broken.
                            ->visible(fn (): bool => ($this->filters['interval'] ?? null) !== null
                                && $this->filters['interval'] !== ComparisonInterval::None->value)
                            ->live(),

                        Select::make('depth')
                            ->label(__('accounting.statements.depth'))
                            ->options(fn (): array => array_combine(
                                range(1, StatementOptions::MAX_DEPTH),
                                range(1, StatementOptions::MAX_DEPTH),
                            ))
                            ->helperText(__('accounting.statements.depth_hint'))
                            ->selectablePlaceholder(false)
                            ->live(),

                        Select::make('branch_id')
                            ->label(__('accounting.entries.columns.branch'))
                            ->options(fn (): array => Branch::query()
                                ->orderBy('code')->pluck('name', 'id')->all())
                            ->placeholder(__('accounting.trial_balance.all_branches'))
                            ->live(),

                        Toggle::make('include_empty')
                            ->label(__('accounting.statements.include_empty'))
                            ->helperText(__('accounting.statements.include_empty_hint'))
                            ->live(),
                    ])
                    ->columns(3),
            ]);
    }

    protected function options(): StatementOptions
    {
        return StatementOptions::fromArray($this->filters);
    }
}
