<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\ComparisonInterval;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Models\Branch;
use App\Services\Accounting\Reports\DrillDownResult;
use App\Services\Accounting\Reports\DrillKind;
use App\Services\Accounting\Reports\FinancialStatement;
use App\Services\Accounting\Reports\FinancialStatementDrillContext;
use App\Services\Accounting\Reports\StatementDrillDown;
use App\Services\Accounting\Reports\StatementDrillTarget;
use App\Services\Accounting\Reports\StatementLine;
use App\Services\Accounting\Reports\StatementOptions;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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

    public bool $showDrillModal = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $drillPanel = null;

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
            'drill_down' => false,
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

                        Toggle::make('drill_down')
                            ->label(__('accounting.statements.drill_down'))
                            ->helperText(__('accounting.statements.drill_down_hint'))
                            ->live(),
                    ])
                    ->columns(3),
            ]);
    }

    public function openDrill(int $sectionIndex, string $linePath, int $columnIndex): void
    {
        if (! ($this->filters['drill_down'] ?? false)) {
            return;
        }

        $statement = $this->getStatement();
        $line = $this->findLine($statement, $sectionIndex, $linePath);

        if ($line === null || ! $line->isDrillable()) {
            return;
        }

        if (! $this->amountIsDrillable($line->amounts[$columnIndex] ?? '0')) {
            return;
        }

        $this->runDrill($line->drill, $columnIndex, $line->name);
    }

    public function openDrillSection(int $sectionIndex, int $columnIndex, string $target = 'total'): void
    {
        if (! ($this->filters['drill_down'] ?? false)) {
            return;
        }

        $statement = $this->getStatement();
        $section = $statement->sections[$sectionIndex] ?? null;

        if ($section === null || ! $section->isDrillable()) {
            return;
        }

        $amount = $target === 'summary'
            ? ($section->totals[$columnIndex] ?? '0')
            : ($section->totals[$columnIndex] ?? '0');

        if (! $this->amountIsDrillable($amount)) {
            return;
        }

        $title = $target === 'summary'
            ? $section->title()
            : $section->totalLabel();

        $this->runDrill($section->drill, $columnIndex, $title);
    }

    public function closeDrill(): void
    {
        $this->showDrillModal = false;
        $this->drillPanel = null;
    }

    public function journalEntryUrl(string $entryId): string
    {
        return JournalEntryResource::getUrl('view', [
            'record' => $entryId,
            'tenant' => Filament::getTenant(),
        ]);
    }

    protected function options(): StatementOptions
    {
        return StatementOptions::fromArray($this->filters);
    }

    protected function drillFrom(): ?CarbonImmutable
    {
        $from = $this->filters['from'] ?? null;

        return blank($from) ? null : CarbonImmutable::parse($from);
    }

    protected function drillTo(): ?CarbonImmutable
    {
        $to = $this->filters['to'] ?? null;

        return blank($to) ? null : CarbonImmutable::parse($to);
    }

    protected function drillAsOf(): ?CarbonImmutable
    {
        $asOf = $this->filters['as_of'] ?? null;

        return blank($asOf) ? null : CarbonImmutable::parse($asOf);
    }

    private function runDrill(?StatementDrillTarget $target, int $columnIndex, string $title): void
    {
        $statement = $this->getStatement();
        $period = $statement->periods[$columnIndex] ?? null;

        if ($period === null || $target === null) {
            return;
        }

        $context = new FinancialStatementDrillContext(
            statement: $statement,
            columnIndex: $columnIndex,
            period: $period,
            filters: $this->options()->filters,
            options: $this->options(),
            from: $this->drillFrom(),
            to: $this->drillTo(),
            asOf: $this->drillAsOf(),
        );

        $this->drillPanel = $this->serializeDrill(
            app(StatementDrillDown::class)->execute(
                target: $target,
                context: $context,
                lineTitle: $title,
            ),
        );
        $this->showDrillModal = true;
    }

    private function amountIsDrillable(string $amount): bool
    {
        return bccomp($amount, '0', 4) !== 0;
    }

    private function findLine(FinancialStatement $statement, int $sectionIndex, string $path): ?StatementLine
    {
        $section = $statement->sections[$sectionIndex] ?? null;

        if ($section === null || $path === '') {
            return null;
        }

        /** @var list<int> $indices */
        $indices = array_map(intval(...), explode('.', $path));
        $line = null;
        $lines = $section->lines;

        foreach ($indices as $index) {
            $line = $lines[$index] ?? null;

            if ($line === null) {
                return null;
            }

            $lines = $line->children;
        }

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDrill(DrillDownResult $result): array
    {
        $rows = $result->rows->map(static fn ($row): array => [
            'entryId' => $row->entryId,
            'number' => $row->number,
            'date' => $row->date->toDateString(),
            'description' => $row->description,
            'reference' => $row->reference,
            'debit' => $row->debit,
            'credit' => $row->credit,
            'accountLabel' => $row->accountLabel,
            'runningBalance' => $row->runningBalance,
        ])->all();

        $breakdownRows = $result->breakdownRows->map(static fn ($row): array => [
            'label' => $row->label,
            'signedAmount' => $row->signedAmount,
            'sign' => $row->sign,
        ])->all();

        $hasAccountColumn = collect($rows)->contains(
            static fn (array $row): bool => filled($row['accountLabel']),
        );

        $hasBalanceColumn = collect($rows)->contains(
            static fn (array $row): bool => filled($row['runningBalance']),
        );

        $isBreakdown = in_array($result->kind, [DrillKind::Composite, DrillKind::SectionBreakdown], true);

        return [
            'title' => $result->title,
            'periodLabel' => $result->periodLabel,
            'kind' => $result->kind->value,
            'isFiltered' => $result->isFiltered,
            'isBreakdown' => $isBreakdown,
            'opening' => $result->opening,
            'closing' => $result->closing,
            'periodDebit' => $result->periodDebit,
            'periodCredit' => $result->periodCredit,
            'total' => $result->total,
            'rows' => $rows,
            'breakdownRows' => $breakdownRows,
            'hasAccountColumn' => $hasAccountColumn,
            'hasBalanceColumn' => $hasBalanceColumn,
        ];
    }
}
