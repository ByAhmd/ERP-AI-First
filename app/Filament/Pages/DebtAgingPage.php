<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ContactType;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reports\DebtAging;
use App\Services\Reports\DebtAgingData;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * تقرير أعمار الديون — the unified day-bucket debt aging report.
 *
 * Qoyod's newer operational report: customers and suppliers together,
 * bucketed by days overdue, with a summary view per contact and a details
 * view per document.
 *
 * `$form` is resolved by Livewire's dynamic property handling.
 *
 * @property-read Schema $form
 */
class DebtAgingPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.debt-aging';

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
        return __('accounting.debt_aging.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.debt_aging.title');
    }

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        $timezone = $tenant instanceof Company ? $tenant->timezone : 'Asia/Riyadh';

        $this->form->fill([
            'as_of' => CarbonImmutable::now($timezone)->toDateString(),
            'contact_type' => null,
            'contact_id' => null,
            'view_mode' => 'summary',
            'min_amount' => null,
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
                            ->label(__('accounting.debt_aging.as_of'))
                            ->native(false)
                            ->required()
                            ->live(),

                        Select::make('contact_type')
                            ->label(__('accounting.debt_aging.contact_type'))
                            ->options([
                                'customer' => __('sales.contact_type.customer'),
                                'vendor' => __('sales.contact_type.supplier'),
                            ])
                            ->placeholder(__('accounting.debt_aging.all_types'))
                            ->live(),

                        Select::make('contact_id')
                            ->label(__('accounting.debt_aging.contact'))
                            ->options(function (): array {
                                $type = $this->filters['contact_type'] ?? null;

                                return Contact::query()
                                    ->when($type === 'customer', fn ($q) => $q->where('type', ContactType::Customer))
                                    ->when($type === 'vendor', fn ($q) => $q->where('type', ContactType::Supplier))
                                    ->orderBy('contact_name')
                                    ->pluck('contact_name', 'id')
                                    ->all();
                            })
                            ->placeholder(__('accounting.debt_aging.all_contacts'))
                            ->searchable()
                            ->live(),

                        Select::make('view_mode')
                            ->label(__('accounting.debt_aging.view_mode'))
                            ->options([
                                'summary' => __('accounting.debt_aging.view_summary'),
                                'details' => __('accounting.debt_aging.view_details'),
                            ])
                            ->selectablePlaceholder(false)
                            ->live(),

                        TextInput::make('min_amount')
                            ->label(__('accounting.debt_aging.min_amount'))
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true),
                    ])
                    ->columns(5),
            ]);
    }

    public function getData(): DebtAgingData
    {
        $asOf = $this->filters['as_of'] ?? null;

        if (blank($asOf)) {
            return new DebtAgingData(summary: [], details: [], totals: ['total' => '0.0000']);
        }

        $type = $this->filters['contact_type'] ?? null;
        $contact = $this->filters['contact_id'] ?? null;
        $min = $this->filters['min_amount'] ?? null;

        return app(DebtAging::class)->build(
            asOf: CarbonImmutable::parse((string) $asOf),
            contactType: filled($type) && is_string($type) ? $type : null,
            contactId: filled($contact) && is_string($contact) ? $contact : null,
            minAmount: filled($min) && is_numeric($min) ? (string) $min : '0',
            view: ($this->filters['view_mode'] ?? 'summary') === 'details' ? 'details' : 'summary',
        );
    }

    public function isDetails(): bool
    {
        return ($this->filters['view_mode'] ?? 'summary') === 'details';
    }
}
