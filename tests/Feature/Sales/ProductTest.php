<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\ProductType;
use App\Enums\SystemAccount;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitType;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The catalogue an invoice line refers to.
 *
 * The rule worth holding onto is that a price here is a default a document
 * copies, never a value a document reads back: an invoice raised in March must
 * keep reporting March's price after the catalogue is re-priced in April.
 */
final class ProductTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Trading');
        $this->makeChartOfAccounts($this->company);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);
    }

    #[Test]
    public function the_template_seeds_a_default_category_and_qoyods_units(): void
    {
        $default = ProductCategory::query()->where('is_default', true)->firstOrFail();

        $this->assertSame('الصنف الأساسي', $default->name);
        $this->assertSame(7, ProductUnitType::query()->count());
        $this->assertTrue(ProductUnitType::query()->where('name', 'قطعة')->exists());
    }

    #[Test]
    public function the_template_is_idempotent(): void
    {
        $created = app(CatalogueTemplate::class)->applyTo($this->company);

        $this->assertSame(['categories' => 0, 'units' => 0], $created);
        $this->assertSame(1, ProductCategory::query()->count());
        $this->assertSame(7, ProductUnitType::query()->count());
    }

    #[Test]
    public function a_product_fills_in_the_things_qoyod_requires_but_supplies(): void
    {
        // Serial, category and tax are all required on Qoyod's form and all
        // pre-filled by it. Requiring them without supplying them would be a
        // worse screen.
        $product = Product::create([
            'name' => 'كرسي مكتب',
            'name_en' => 'Office Chair',
            'unit_type_id' => $this->unit('قطعة'),
            'selling_price' => '450',
        ]);

        $this->assertSame('P0001', $product->sku);
        $this->assertSame(
            ProductCategory::query()->where('is_default', true)->value('id'),
            $product->category_id,
        );
        $this->assertSame(
            Tax::query()->where('is_default', true)->value('id'),
            $product->tax_id,
        );
    }

    #[Test]
    public function serials_run_in_sequence_and_an_explicit_one_is_kept(): void
    {
        Product::create([
            'name' => 'أول', 'name_en' => 'First',
            'unit_type_id' => $this->unit('قطعة'), 'selling_price' => '1',
        ]);

        $second = Product::create([
            'name' => 'ثاني', 'name_en' => 'Second',
            'unit_type_id' => $this->unit('قطعة'), 'selling_price' => '1',
        ]);

        $explicit = Product::create([
            'name' => 'قديم', 'name_en' => 'Legacy', 'sku' => 'OLD-7',
            'unit_type_id' => $this->unit('قطعة'), 'selling_price' => '1',
        ]);

        $this->assertSame('P0002', $second->sku);
        $this->assertSame('OLD-7', $explicit->sku);
    }

    #[Test]
    public function a_price_for_a_side_the_product_is_not_on_is_discarded(): void
    {
        // Qoyod hides the selling price until يُباع is ticked. Keeping a stale
        // figure behind an unticked box would let it be invoiced the moment
        // someone ticked it again.
        $product = Product::create([
            'name' => 'مادة', 'name_en' => 'Material',
            'unit_type_id' => $this->unit('كج'),
            'is_sold' => false,
            'is_purchased' => true,
            'selling_price' => '999',
            'buying_price' => '40',
        ]);

        $this->assertNull($product->selling_price);
        $this->assertSame('40.0000', $product->buying_price);
    }

    #[Test]
    public function only_sellable_active_products_are_offered_to_a_document(): void
    {
        Product::create([
            'name' => 'يُباع', 'name_en' => 'Sold',
            'unit_type_id' => $this->unit('قطعة'), 'selling_price' => '10',
        ]);

        Product::create([
            'name' => 'للشراء فقط', 'name_en' => 'Purchase only',
            'unit_type_id' => $this->unit('قطعة'),
            'is_sold' => false, 'is_purchased' => true, 'buying_price' => '5',
        ]);

        Product::create([
            'name' => 'متوقف', 'name_en' => 'Retired',
            'unit_type_id' => $this->unit('قطعة'), 'selling_price' => '10',
            'is_active' => false,
        ]);

        $sellable = Product::query()->sellable()->get();

        $this->assertCount(1, $sellable);
        $this->assertSame('يُباع', $sellable->first()->name);
    }

    #[Test]
    public function services_are_not_stock_and_products_are(): void
    {
        // A service invoiced today consumes nothing from a warehouse, so it
        // posts revenue and no cost of sale.
        $this->assertTrue(ProductType::Product->isStocked());
        $this->assertTrue(ProductType::RawMaterial->isStocked());
        $this->assertFalse(ProductType::Service->isStocked());
        $this->assertFalse(ProductType::Expense->isStocked());
    }

    #[Test]
    public function sales_revenue_resolves_by_role_rather_than_by_code(): void
    {
        // Qoyod carries no revenue account on a product or its category and
        // posts to one company-level default. This is that default.
        $revenue = app(AccountRegistry::class)->get(SystemAccount::SalesRevenue);

        $this->assertSame('4100', $revenue->code);
        $this->assertSame([], app(AccountRegistry::class)->missing());
    }

    #[Test]
    public function a_product_is_retired_rather_than_erased(): void
    {
        $product = Product::create([
            'name' => 'كرسي', 'name_en' => 'Chair',
            'unit_type_id' => $this->unit('قطعة'), 'selling_price' => '450',
        ]);

        $product->delete();

        $this->assertSoftDeleted($product);
    }

    #[Test]
    public function products_do_not_leak_between_companies(): void
    {
        Product::create([
            'name' => 'كرسي', 'name_en' => 'Chair',
            'unit_type_id' => $this->unit('قطعة'), 'selling_price' => '450',
        ]);

        $rival = $this->makeOtherCompany('Globex Industrial');
        $this->makeChartOfAccounts($rival);

        $seen = app(CompanyContext::class)->forCompany(
            $rival,
            static fn (): int => Product::query()->count(),
        );

        $this->assertSame(0, $seen);
    }

    private function unit(string $name): string
    {
        return ProductUnitType::query()->where('name', $name)->firstOrFail()->getKey();
    }
}
