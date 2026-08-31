<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\ProductStock;
use App\Models\ProductUnitType;
use App\Models\StockMovement;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use App\Services\Inventory\Exceptions\TransferRejected;
use App\Services\Inventory\InventoryTransferPoster;
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
 * Inventory transfers.
 *
 * The invariants: quantities change branches, nothing else changes — the
 * company total, the total value, the average and the ledger all stand
 * still, and the movement pair records the journey at zero value so the
 * value_after audit chain stays unbroken.
 */
final class InventoryTransferTest extends TestCase
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

        // 10 units at 15 into the main branch.
        DB::transaction(fn () => app(StockLedger::class)->receive(
            $this->product, $this->main, CarbonImmutable::parse('2026-03-01'),
            [new StockLine($this->product->getKey(), '10', '150.00')],
        ));
    }

    #[Test]
    public function a_transfer_moves_quantity_and_nothing_else(): void
    {
        $transfer = $this->approvedTransfer('4');

        $this->assertTrue($transfer->isApproved());

        // Branch quantities changed hands.
        $this->assertSame('6.0000', $this->branchQty($this->main));
        $this->assertSame('4.0000', $this->branchQty($this->second));

        // Company totals, value and average all stood still.
        $cost = ProductCost::query()->where('product_id', $this->product->getKey())->firstOrFail();
        $this->assertSame('10.0000', (string) $cost->quantity_on_hand);
        $this->assertSame('150.0000', (string) $cost->total_value);
        $this->assertSame('15.0000', (string) $cost->average_cost);

        // And the ledger heard nothing.
        $this->assertSame(0, JournalEntry::query()->count());
    }

    #[Test]
    public function the_movement_pair_records_the_journey_at_zero_value(): void
    {
        $this->approvedTransfer('4');

        $movements = StockMovement::query()
            ->where('source_type', InventoryTransfer::class)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements);

        $out = $movements[0];
        $in = $movements[1];

        $this->assertSame('-4.0000', (string) $out->quantity);
        $this->assertSame('4.0000', (string) $in->quantity);
        // Zero value both ways: the audit chain's value_after is unchanged.
        $this->assertSame('0.0000', (string) $out->value);
        $this->assertSame('150.0000', (string) $out->value_after);
        $this->assertSame('150.0000', (string) $in->value_after);
        // The average travels as information.
        $this->assertSame('15.0000', (string) $out->unit_cost);
        $this->assertSame('6.0000', (string) $out->branch_qty_after);
        $this->assertSame('4.0000', (string) $in->branch_qty_after);
    }

    #[Test]
    public function a_transfer_beyond_the_source_branch_stock_is_refused(): void
    {
        // Stock exists at MAIN; shipping 4 out of the empty second branch
        // must refuse — destination stock cannot ship from the source.
        $transfer = $this->draftTransfer('4', from: $this->second, to: $this->main);

        try {
            app(InventoryTransferPoster::class)->approve($transfer);
            $this->fail('Transferring more than the source holds should refuse.');
        } catch (StockRuleViolation) {
            $this->assertSame('10.0000', $this->branchQty($this->main));
            $this->assertTrue($transfer->refresh()->isDraft());
        }
    }

    #[Test]
    public function a_transfer_to_the_same_branch_is_refused(): void
    {
        $transfer = $this->draftTransfer('4', from: $this->main, to: $this->main);

        $this->expectException(TransferRejected::class);

        app(InventoryTransferPoster::class)->approve($transfer);
    }

    #[Test]
    public function an_untracked_product_cannot_travel(): void
    {
        $untracked = Product::create([
            'name' => 'خدمة',
            'name_en' => 'Service',
            'unit_type_id' => ProductUnitType::query()->value('id'),
        ]);

        $transfer = InventoryTransfer::create([
            'reference' => app(InventoryTransferPoster::class)->nextReference(),
            'from_branch_id' => $this->main->getKey(),
            'to_branch_id' => $this->second->getKey(),
            'transfer_date' => today(),
        ]);

        $transfer->items()->create([
            'product_id' => $untracked->getKey(),
            'quantity' => '1',
        ]);

        $this->expectException(StockRuleViolation::class);

        app(InventoryTransferPoster::class)->approve($transfer->refresh());
    }

    #[Test]
    public function an_approved_transfer_cannot_be_approved_again(): void
    {
        $transfer = $this->approvedTransfer('2');

        $this->expectException(TransferRejected::class);

        app(InventoryTransferPoster::class)->approve($transfer);
    }

    #[Test]
    public function transfers_number_from_their_own_series(): void
    {
        $this->assertSame('TRF-00001', app(InventoryTransferPoster::class)->nextReference());
        $this->assertSame('TRF-00002', app(InventoryTransferPoster::class)->nextReference());
    }

    #[Test]
    public function transferred_stock_ships_from_the_destination_afterwards(): void
    {
        $this->approvedTransfer('4');

        // The second branch can now issue what it received.
        $result = DB::transaction(fn () => app(StockLedger::class)->issue(
            $this->product, $this->second, CarbonImmutable::parse('2026-03-10'),
            [new StockLine($this->product->getKey(), '3')],
        ));

        $this->assertSame('45.0000', $result->valueFor($this->product->getKey()));
        $this->assertSame('1.0000', $this->branchQty($this->second));
    }

    // ------------------------------------------------------------------ helpers

    private function approvedTransfer(string $quantity): InventoryTransfer
    {
        return app(InventoryTransferPoster::class)->approve(
            $this->draftTransfer($quantity, $this->main, $this->second),
        );
    }

    private function draftTransfer(string $quantity, Branch $from, Branch $to): InventoryTransfer
    {
        $transfer = InventoryTransfer::create([
            'reference' => app(InventoryTransferPoster::class)->nextReference(),
            'from_branch_id' => $from->getKey(),
            'to_branch_id' => $to->getKey(),
            'transfer_date' => today(),
        ]);

        $transfer->items()->create([
            'product_id' => $this->product->getKey(),
            'quantity' => $quantity,
        ]);

        return $transfer->refresh();
    }

    private function branchQty(Branch $branch): string
    {
        $qty = ProductStock::query()
            ->where('product_id', $this->product->getKey())
            ->where('branch_id', $branch->getKey())
            ->value('quantity_on_hand');

        return bcadd((string) ($qty ?? '0'), '0', 4);
    }
}
