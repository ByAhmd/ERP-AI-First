<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\BranchStocksRelationManager;
use App\Filament\Resources\Products\RelationManagers\StockMovementsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\User;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\StockLedger;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The product view's stock story — Qoyod's per-product تحركات screen.
 */
final class ProductMovementsScreenTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Trading');
        $this->admin = $this->makeAdministrator($this->company, 'admin@acme.test');

        $this->actingInPanel($this->admin, $this->company);

        $this->makeChartOfAccounts($this->company);
        $this->makeFiscalYear($this->company, 2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->branch = Branch::query()->where('is_default', true)->firstOrFail();

        $this->product = Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'track_inventory' => true,
        ]);
    }

    #[Test]
    public function the_view_page_renders_with_the_stock_summary(): void
    {
        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '10', '150.00')],
        ));

        Livewire::actingAs($this->admin)
            ->test(ViewProduct::class, ['record' => $this->product->getKey()])
            ->assertOk()
            ->assertSee('15.00');
    }

    #[Test]
    public function the_movements_table_lists_the_stream_with_running_balances(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(function () use ($ledger): void {
            $ledger->receive($this->product, $this->branch, CarbonImmutable::parse('2026-03-01'), [
                new StockLine($this->product->getKey(), '10', '100.00'),
            ]);
            $ledger->issue($this->product, $this->branch, CarbonImmutable::parse('2026-03-05'), [
                new StockLine($this->product->getKey(), '4'),
            ]);
        });

        Livewire::actingAs($this->admin)
            ->test(StockMovementsRelationManager::class, [
                'ownerRecord' => $this->product,
                'pageClass' => ViewProduct::class,
            ])
            ->assertOk()
            // Both movements render with their running balances: 10 in
            // leaves 10; 4 out leaves 6.
            ->assertSee('10.00')
            ->assertSee('6.00');
    }

    #[Test]
    public function the_branch_stocks_table_shows_per_location_quantities(): void
    {
        $second = Branch::create(['code' => 'B2', 'name' => 'فرع جدة']);

        $ledger = app(StockLedger::class);

        DB::transaction(function () use ($ledger, $second): void {
            $ledger->receive($this->product, $this->branch, CarbonImmutable::parse('2026-03-01'), [
                new StockLine($this->product->getKey(), '10', '100.00'),
            ]);
            $ledger->receive($this->product, $second, CarbonImmutable::parse('2026-03-02'), [
                new StockLine($this->product->getKey(), '4', '40.00'),
            ]);
        });

        Livewire::actingAs($this->admin)
            ->test(BranchStocksRelationManager::class, [
                'ownerRecord' => $this->product,
                'pageClass' => ViewProduct::class,
            ])
            ->assertOk()
            ->assertSee('فرع جدة');
    }

    #[Test]
    public function untracked_products_show_no_stock_tables(): void
    {
        $untracked = Product::create([
            'name' => 'خدمة',
            'name_en' => 'Service',
            'unit_type_id' => ProductUnitType::query()->value('id'),
        ]);

        $this->assertFalse(
            StockMovementsRelationManager::canViewForRecord($untracked, ViewProduct::class),
        );
        $this->assertFalse(
            BranchStocksRelationManager::canViewForRecord($untracked, ViewProduct::class),
        );

        Livewire::actingAs($this->admin)
            ->test(ViewProduct::class, ['record' => $untracked->getKey()])
            ->assertOk();
    }
}
