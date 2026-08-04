<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\Branch;
use App\Models\DimensionValue;
use App\Services\Accounting\Reports\GeneralLedger;
use App\Services\Accounting\Reports\LedgerMovement;
use App\Services\Accounting\Reports\ReportFilters;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * The general ledger for a single account.
 *
 * An account has to be chosen before anything is shown. A ledger across every
 * account at once is not a report anyone reads — it is the journal, which the
 * journal entries screen already provides.
 *
 * @property-read Schema $form
 */
class GeneralLedgerPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?int $navigationSort = 41;

    protected string $view = 'filament.pages.general-ledger';

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
        return __('accounting.general_ledger.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.general_ledger.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => CarbonImmutable::now()->startOfYear()->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
                        Select::make('account_id')
                            ->label(__('accounting.general_ledger.account'))
                            ->options(fn (): array => Account::query()
                                // Only leaves hold movements; a group account's
                                // ledger would always be empty.
                                ->where('is_postable', true)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (Account $a): array => [$a->getKey() => $a->displayName()])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->columnSpan(2),

                        DatePicker::make('from')
                            ->label(__('accounting.general_ledger.from'))
                            ->native(false)
                            ->required()
                            ->live(),

                        DatePicker::make('to')
                            ->label(__('accounting.general_ledger.to'))
                            ->native(false)
                            ->required()
                            ->live(),

                        Select::make('branch_id')
                            ->label(__('accounting.entries.columns.branch'))
                            ->options(fn (): array => Branch::query()
                                ->orderBy('code')->pluck('name', 'id')->all())
                            ->placeholder(__('accounting.trial_balance.all_branches'))
                            ->live(),

                        Select::make('dimension_value_id')
                            ->label(__('accounting.dimensions.label'))
                            ->options(fn (): array => DimensionValue::query()
                                ->with('dimension')
                                ->where('is_active', true)
                                ->get()
                                // Grouped by dimension so two values sharing a
                                // name stay distinguishable.
                                ->mapWithKeys(fn (DimensionValue $v): array => [
                                    $v->getKey() => $v->dimension->displayName().' — '.$v->displayName(),
                                ])
                                ->all())
                            ->searchable()
                            ->placeholder(__('accounting.general_ledger.all_dimensions'))
                            ->live(),
                    ])
                    ->columns(3),
            ]);
    }

    public function getAccount(): ?Account
    {
        $id = $this->filters['account_id'] ?? null;

        return blank($id) ? null : Account::query()->find($id);
    }

    /**
     * @return Collection<int, LedgerMovement>
     */
    public function getMovements(): Collection
    {
        $account = $this->getAccount();

        if ($account === null || blank($this->filters['from'] ?? null) || blank($this->filters['to'] ?? null)) {
            return collect();
        }

        return app(GeneralLedger::class)->movements(
            account: $account,
            from: CarbonImmutable::parse($this->filters['from']),
            to: CarbonImmutable::parse($this->filters['to']),
            filters: $this->activeFilters(),
        );
    }

    /**
     * @return array{opening: string, debit: string, credit: string, closing: string}|null
     */
    public function getSummary(): ?array
    {
        $account = $this->getAccount();

        if ($account === null) {
            return null;
        }

        $ledger = app(GeneralLedger::class);

        return $ledger->summarise(
            $this->getMovements(),
            $ledger->openingBalance(
                $account,
                CarbonImmutable::parse($this->filters['from']),
                $this->activeFilters(),
            ),
        );
    }

    private function activeFilters(): ReportFilters
    {
        return ReportFilters::fromArray($this->filters);
    }
}
