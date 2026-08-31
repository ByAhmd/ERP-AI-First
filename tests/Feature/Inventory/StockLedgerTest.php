<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\ProductUnitType;
use App\Models\StockMovement;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use App\Services\Inventory\StockLedger;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\Exceptions\ProductRuleViolation;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The stock ledger's arithmetic, before any poster touches it.
 *
 * The riskiest line in the inventory slice is the one computing the new
 * average, so it gets a table of sequences first: receipts moving the
 * average, issues relieving at it, the terminal relief that leaves no
 * orphan halalas, and the refusals that keep the average unpoisonable.
 */
final class StockLedgerTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

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

    /** Buy 10@10, buy 10@20 → avg 15; sell 5 → relief 75, value 225; buy 10@30 → avg 21. */
    #[Test]
    public function the_average_moves_as_the_textbook_says(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(function () use ($ledger): void {
            $ledger->receive($this->product, $this->branch, CarbonImmutable::parse('2026-03-01'), [
                new StockLine($this->product->getKey(), '10', '100.00'),
            ]);
            $ledger->receive($this->product, $this->branch, CarbonImmutable::parse('2026-03-02'), [
                new StockLine($this->product->getKey(), '10', '200.00'),
            ]);
        });

        $cost = $this->cost();
        $this->assertSame('20.0000', (string) $cost->quantity_on_hand);
        $this->assertSame('300.0000', (string) $cost->total_value);
        $this->assertSame('15.0000', (string) $cost->average_cost);

        $result = DB::transaction(fn () => $ledger->issue(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-05'),
            [new StockLine($this->product->getKey(), '5')],
        ));

        $this->assertSame('75.0000', $result->valueFor($this->product->getKey()));

        $cost = $this->cost();
        $this->assertSame('15.0000', (string) $cost->quantity_on_hand);
        $this->assertSame('225.0000', (string) $cost->total_value);

        DB::transaction(fn () => $ledger->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-06'),
            [new StockLine($this->product->getKey(), '10', '300.00')],
        ));

        $this->assertSame('21.0000', (string) $this->cost()->average_cost);
    }

    /** Selling the last unit takes the exact remaining value — no orphan halalas. */
    #[Test]
    public function the_terminal_relief_leaves_exactly_zero_value(): void
    {
        $ledger = app(StockLedger::class);

        // 10.00 across three units: avg 3.3333, and naive qty×avg would
        // relieve 3.33 + 3.33 + 3.33 = 9.99, stranding 0.01 forever.
        DB::transaction(fn () => $ledger->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '3', '10.00')],
        ));

        $reliefs = [];

        foreach (range(1, 3) as $i) {
            $result = DB::transaction(fn () => $ledger->issue(
                $this->product, $this->branch, CarbonImmutable::parse('2026-03-0'.(1 + $i)),
                [new StockLine($this->product->getKey(), '1')],
            ));

            $reliefs[] = $result->valueFor($this->product->getKey());
        }

        $cost = $this->cost();
        $this->assertSame('0.0000', (string) $cost->quantity_on_hand);
        $this->assertSame('0.0000', (string) $cost->total_value);
        // The three reliefs sum to exactly what came in.
        $this->assertSame('10.0000', bcadd(bcadd($reliefs[0], $reliefs[1], 4), $reliefs[2], 4));
    }

    #[Test]
    public function a_fully_discounted_receipt_dilutes_the_average(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(function () use ($ledger): void {
            $ledger->receive($this->product, $this->branch, CarbonImmutable::parse('2026-03-01'), [
                new StockLine($this->product->getKey(), '10', '100.00'),
            ]);
            // Free goods: quantity in, value zero.
            $ledger->receive($this->product, $this->branch, CarbonImmutable::parse('2026-03-02'), [
                new StockLine($this->product->getKey(), '10', '0'),
            ]);
        });

        $this->assertSame('5.0000', (string) $this->cost()->average_cost);
    }

    #[Test]
    public function issuing_more_than_the_branch_holds_is_refused(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(fn () => $ledger->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '5', '50.00')],
        ));

        // A second branch with stock elsewhere must not satisfy this one.
        $other = Branch::create(['code' => 'B2', 'name' => 'فرع ثانٍ']);

        try {
            DB::transaction(fn () => $ledger->issue(
                $this->product, $other, CarbonImmutable::parse('2026-03-02'),
                [new StockLine($this->product->getKey(), '1')],
            ));
            $this->fail('Stock at another branch must not ship from this one.');
        } catch (StockRuleViolation) {
            // Nothing moved anywhere.
            $this->assertSame('5.0000', (string) $this->cost()->quantity_on_hand);
        }

        $this->expectException(StockRuleViolation::class);

        DB::transaction(fn () => $ledger->issue(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-02'),
            [new StockLine($this->product->getKey(), '6')],
        ));
    }

    #[Test]
    public function two_lines_of_one_product_fold_into_one_movement(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(fn () => $ledger->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-01'),
            [
                new StockLine($this->product->getKey(), '3', '30.00'),
                new StockLine($this->product->getKey(), '7', '70.00'),
            ],
        ));

        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame('10.0000', (string) $this->cost()->quantity_on_hand);
    }

    #[Test]
    public function movements_carry_running_balances_and_the_application_order(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(fn () => $ledger->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-10'),
            [new StockLine($this->product->getKey(), '10', '100.00')],
        ));

        // Backdated receipt: earlier date, later application order.
        DB::transaction(fn () => $ledger->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '10', '300.00')],
        ));

        $movements = StockMovement::query()->orderBy('id')->get();

        $this->assertSame('100.0000', (string) $movements[0]->value_after);
        $this->assertSame('400.0000', (string) $movements[1]->value_after);
        // Running-forward: the backdated cost applies now, not by date.
        $this->assertSame('20.0000', (string) $this->cost()->average_cost);
        $this->assertTrue($movements[1]->movement_date->lessThan($movements[0]->movement_date));
    }

    #[Test]
    public function the_tracking_flag_freezes_at_the_first_movement(): void
    {
        $ledger = app(StockLedger::class);

        DB::transaction(fn () => $ledger->receive(
            $this->product, $this->branch, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '1', '10.00')],
        ));

        $this->expectException(ProductRuleViolation::class);

        $this->product->refresh()->forceFill(['track_inventory' => false])->save();
    }

    #[Test]
    public function enabling_tracking_creates_the_cost_row(): void
    {
        $fresh = Product::create([
            'name' => 'منتج جديد',
            'name_en' => 'New Product',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'track_inventory' => true,
        ]);

        $this->assertNotNull($fresh->costRecord()->first());
    }

    #[Test]
    public function untracked_products_have_no_door_into_the_ledger(): void
    {
        $untracked = Product::create([
            'name' => 'خدمة',
            'name_en' => 'Service',
            'unit_type_id' => ProductUnitType::query()->value('id'),
        ]);

        $this->assertFalse($untracked->isStocked());

        $this->expectException(StockRuleViolation::class);

        DB::transaction(fn () => app(StockLedger::class)->receive(
            $untracked, $this->branch, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($untracked->getKey(), '1', '10.00')],
        ));
    }

    private function cost(): ProductCost
    {
        return ProductCost::query()
            ->where('product_id', $this->product->getKey())
            ->firstOrFail();
    }
}
