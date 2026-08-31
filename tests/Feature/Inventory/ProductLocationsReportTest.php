<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\Reports\ProductLocations;
use App\Services\Inventory\StockLedger;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The product locations report — the crosstab of tracked products across
 * branches, zero-filled so "where is everything" is answered completely.
 */
final class ProductLocationsReportTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Branch $main;

    private Branch $second;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->main = Branch::query()->where('is_default', true)->firstOrFail();
        $this->second = Branch::create(['code' => 'B2', 'name' => 'فرع جدة']);

        $this->product = Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'track_inventory' => true,
        ]);
    }

    #[Test]
    public function the_crosstab_shows_quantities_per_branch_with_totals(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(function () use ($ledger): void {
            $ledger->receive($this->product, $this->main, CarbonImmutable::parse('2026-03-01'), [
                new StockLine($this->product->getKey(), '10', '100.00'),
            ]);
            $ledger->receive($this->product, $this->second, CarbonImmutable::parse('2026-03-02'), [
                new StockLine($this->product->getKey(), '4', '40.00'),
            ]);
        });

        $report = app(ProductLocations::class)->build();

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];

        $this->assertSame('10.0000', $row['quantities'][$this->main->getKey()]);
        $this->assertSame('4.0000', $row['quantities'][$this->second->getKey()]);
        $this->assertSame('14.0000', $row['total']);
        $this->assertSame('14.0000', $report['totals']['total']);
    }

    #[Test]
    public function a_never_moved_product_still_lists_at_zero(): void
    {
        $report = app(ProductLocations::class)->build();

        $this->assertCount(1, $report['rows']);
        $this->assertSame('0.0000', $report['rows'][0]['total']);
        // Untracked products stay out entirely.
        Product::create([
            'name' => 'خدمة',
            'name_en' => 'Service',
            'unit_type_id' => ProductUnitType::query()->value('id'),
        ]);

        $this->assertCount(1, app(ProductLocations::class)->build()['rows']);
    }

    #[Test]
    public function the_branch_filter_narrows_to_one_column(): void
    {
        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->product, $this->main, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '10', '100.00')],
        ));

        $report = app(ProductLocations::class)->build($this->second->getKey());

        $this->assertCount(1, $report['branches']);
        $this->assertSame('0.0000', $report['rows'][0]['quantities'][$this->second->getKey()]);
        // The row's total is the shown columns' total — one branch filtered
        // means that branch's figure, not the company's.
        $this->assertSame('0.0000', $report['rows'][0]['total']);
    }

    #[Test]
    public function another_companys_stock_is_invisible(): void
    {
        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->product, $this->main, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '10', '100.00')],
        ));

        $this->makeAccountingCompany(2026);

        $report = app(ProductLocations::class)->build();

        $this->assertSame([], $report['rows']);
    }
}
