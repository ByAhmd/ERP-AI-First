<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Services\Inventory\Reports\ProductLocations;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * تقرير مواقع المنتجات.
 *
 * `$form` is resolved by Livewire's dynamic property handling.
 *
 * @property-read Schema $form
 */
class ProductLocationsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 85;

    protected string $view = 'filament.pages.product-locations';

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
        return __('inventory.locations_report.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('inventory.locations_report.title');
    }

    public function mount(): void
    {
        $this->form->fill(['branch_id' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Section::make()
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('inventory.locations_report.branch'))
                            ->options(fn (): array => Branch::query()
                                ->where('is_active', true)
                                ->orderBy('code')
                                ->pluck('name', 'id')
                                ->all())
                            ->placeholder(__('inventory.locations_report.all_branches'))
                            ->live(),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * @return array{branches: Collection<int, Branch>,
     *     rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function getReport(): array
    {
        $branch = $this->filters['branch_id'] ?? null;

        return app(ProductLocations::class)->build(
            filled($branch) && is_string($branch) ? $branch : null,
        );
    }
}
