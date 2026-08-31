<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockAdjustmentKind;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\ProductUnitType;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentPoster;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The stock adjustment screens, driven as a person drives them.
 */
final class StockAdjustmentPanelTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

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

        $this->product = Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'track_inventory' => true,
        ]);
    }

    #[Test]
    public function the_list_page_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListStockAdjustments::class)
            ->assertOk();
    }

    #[Test]
    public function the_create_form_opens_with_a_reference_in_the_adjustment_series(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CreateStockAdjustment::class);

        $component->assertOk();
        $component->assertFormSet(['reference' => 'ADJ-00001']);
    }

    #[Test]
    public function an_opening_typed_into_the_screen_approves_and_seeds_stock(): void
    {
        $branch = Branch::query()->where('is_default', true)->firstOrFail();

        Livewire::actingAs($this->admin)
            ->test(CreateStockAdjustment::class)
            ->fillForm([
                'kind' => StockAdjustmentKind::Opening->value,
                'branch_id' => $branch->getKey(),
                'adjustment_date' => today()->toDateString(),
                'items' => [[
                    'product_id' => $this->product->getKey(),
                    'quantity_change' => '20',
                    'unit_cost' => '7.5',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $adjustment = StockAdjustment::query()->firstOrFail();

        Livewire::actingAs($this->admin)
            ->test(EditStockAdjustment::class, ['record' => $adjustment->getKey()])
            ->callAction('approve');

        $this->assertTrue($adjustment->refresh()->isApproved());

        $cost = ProductCost::query()->where('product_id', $this->product->getKey())->firstOrFail();
        $this->assertSame('20.0000', (string) $cost->quantity_on_hand);
        $this->assertSame('7.5000', (string) $cost->average_cost);
    }

    #[Test]
    public function an_approved_adjustment_cannot_be_opened_for_editing(): void
    {
        $branch = Branch::query()->where('is_default', true)->firstOrFail();

        $adjustment = StockAdjustment::create([
            'reference' => 'ADJ-TEST',
            'kind' => StockAdjustmentKind::Opening,
            'branch_id' => $branch->getKey(),
            'adjustment_date' => today(),
        ]);

        $adjustment->items()->create([
            'product_id' => $this->product->getKey(),
            'quantity_change' => '5',
            'unit_cost' => '10',
        ]);

        app(StockAdjustmentPoster::class)->approve($adjustment->refresh());

        Livewire::actingAs($this->admin)
            ->test(EditStockAdjustment::class, ['record' => $adjustment->getKey()])
            ->assertRedirect();
    }
}
