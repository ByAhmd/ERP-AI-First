<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\ProductStock;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseInvoice;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\Data\StockResult;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The stock ledger — the single door for every quantity or value mutation.
 *
 * The same design decision as JournalPoster, for the same reason: four
 * posters and an adjustment document all move stock, and the invariant the
 * whole slice defends — the inventory control account and the subledger move
 * by the same number — survives only if the lock, the average, the movement
 * rows and the values posted all come from one place.
 *
 * Costing is moving weighted average, company-wide per product. The value is
 * authoritative; the average is derived, and never computed at zero quantity.
 * A receipt adds the same currency-scale figure the ledger is debited with;
 * an issue relieves at the average resolved HERE, AT APPROVAL TIME, under the
 * lock — the one place the codebase's own snapshot discipline points the
 * wrong way. Prices are copied at draft time and never read back; cost is
 * the opposite: a draft approved a week after it was written must relieve at
 * the average of its approval moment, or the subledger and the entry part
 * company. The resolved figures are then snapshotted onto the movement row
 * as the record of what was applied.
 *
 * When the last unit leaves, the relief is the exact remaining value — not
 * quantity times the rounded average — so no orphan halalas survive in the
 * control account at zero quantity.
 *
 * Lock discipline: distinct product ids sorted ascending, product_costs rows
 * locked in that order (two documents sharing two products in opposite line
 * order must not deadlock), branch stock rows created lazily under that
 * lock. Everything runs inside the calling poster's transaction — never an
 * observer, listener or queued job.
 *
 * Backdating is running-forward, Qoyod's behavior: a bill dated last month
 * enters the average now. Movements store both the document date and the
 * application order (the integer key), so the effect is diagnosable. An
 * as-of valuation reads value_after by date; the cost applied to any single
 * movement reflects posting order — that is the price of GL–subledger
 * identity, and it is Qoyod's price too.
 */
final class StockLedger
{
    private const SCALE = 4;

    /**
     * The morph classes whose ledger entries carry stock movements — the
     * reverse action must never touch these from the ledger screen.
     *
     * @var list<class-string<Model>>
     */
    public const STOCK_SOURCE_TYPES = [
        SalesInvoice::class,
        SalesCreditNote::class,
        PurchaseInvoice::class,
        PurchaseDebitNote::class,
        StockAdjustment::class,
    ];

    /**
     * Receive stock — bills, goods-return credit notes, adjustment increases.
     *
     * Each line carries the VALUE the ledger is being debited with (the
     * currency-scale projection), never a recomputed one: the movement and
     * the 1140 line must be the same number by construction.
     *
     * @param  list<StockLine>  $lines
     * @return StockResult keyed by product id
     */
    public function receive(
        Model $source,
        Branch $branch,
        DateTimeInterface $date,
        array $lines,
    ): StockResult {
        $lines = $this->aggregate($lines);
        $costs = $this->lockCosts($lines);
        $movements = [];

        foreach ($lines as $line) {
            $cost = $costs[$line->productId];

            $newQty = bcadd((string) $cost->quantity_on_hand, $line->quantity, self::SCALE);
            $newValue = bcadd((string) $cost->total_value, (string) $line->value, self::SCALE);

            $cost->forceFill([
                'quantity_on_hand' => $newQty,
                'total_value' => $newValue,
                'average_cost' => $this->average($newValue, $newQty),
            ])->save();

            $stock = $this->branchStock($line->productId, $branch);
            $stock->forceFill([
                'quantity_on_hand' => bcadd((string) $stock->quantity_on_hand, $line->quantity, self::SCALE),
            ])->save();

            $movements[$line->productId] = $this->writeMovement(
                $source, $branch, $date, $line->productId,
                quantity: $line->quantity,
                value: (string) $line->value,
                cost: $cost,
                stock: $stock,
            );
        }

        return new StockResult($movements);
    }

    /**
     * Issue stock — invoices, goods-return debit notes, adjustment decreases.
     *
     * The relief per product is resolved here, under the lock: quantity times
     * the running average at currency scale — except when the issue empties
     * the company position, where the relief is the exact remaining value.
     * The returned reliefs ARE the ledger figures; the caller posts them
     * verbatim.
     *
     * @param  list<StockLine>  $lines  value ignored; quantities positive
     * @return StockResult keyed by product id, values = relief amounts
     */
    public function issue(
        Model $source,
        Branch $branch,
        DateTimeInterface $date,
        array $lines,
        int $currencyScale = 2,
    ): StockResult {
        $lines = $this->aggregate($lines);
        $costs = $this->lockCosts($lines);
        $movements = [];

        foreach ($lines as $line) {
            $cost = $costs[$line->productId];
            $stock = $this->branchStock($line->productId, $branch);

            // Refused at the branch that physically ships. Company-wide
            // quantity can never go negative if no branch does — which is
            // also what makes a poisoned negative average unrepresentable.
            if (bccomp($line->quantity, (string) $stock->quantity_on_hand, self::SCALE) > 0) {
                throw StockRuleViolation::insufficientStock(
                    Product::query()->findOrFail($line->productId),
                    $branch,
                    (string) $stock->quantity_on_hand,
                    $line->quantity,
                );
            }

            $newQty = bcsub((string) $cost->quantity_on_hand, $line->quantity, self::SCALE);

            $relief = bccomp($newQty, '0', self::SCALE) === 0
                // Terminal rule: the last unit takes the exact remaining
                // value with it — no orphan halalas at zero quantity.
                ? (string) $cost->total_value
                : $this->roundToScale(
                    BigRational::of($this->average((string) $cost->total_value, (string) $cost->quantity_on_hand))
                        ->multipliedBy(BigRational::of($line->quantity)),
                    $currencyScale,
                );

            $newValue = bcsub((string) $cost->total_value, $relief, self::SCALE);

            $cost->forceFill([
                'quantity_on_hand' => $newQty,
                'total_value' => $newValue,
                'average_cost' => $this->average($newValue, $newQty),
            ])->save();

            $stock->forceFill([
                'quantity_on_hand' => bcsub((string) $stock->quantity_on_hand, $line->quantity, self::SCALE),
            ])->save();

            $movements[$line->productId] = $this->writeMovement(
                $source, $branch, $date, $line->productId,
                quantity: bcmul($line->quantity, '-1', self::SCALE),
                value: bcmul($relief, '-1', self::SCALE),
                cost: $cost,
                stock: $stock,
            );
        }

        return new StockResult($movements);
    }

    /**
     * Move stock between branches — no value moves with it.
     *
     * With one inventory account, a transfer's ledger effect nets to zero,
     * so the pair of movements it writes carry zero VALUE while the branch
     * quantities change hands: the company total and total value stay put,
     * which is what keeps the value_after audit chain unbroken. The unit
     * cost on each movement records the average the goods travelled at,
     * for the reader.
     *
     * Refused at the SOURCE branch when quantity is short — stock at the
     * destination cannot ship from the source.
     *
     * @param  list<StockLine>  $lines  value ignored; quantities positive
     * @return StockResult the outbound movements, keyed by product
     */
    public function transfer(
        Model $source,
        Branch $from,
        Branch $to,
        DateTimeInterface $date,
        array $lines,
    ): StockResult {
        $lines = $this->aggregate($lines);
        $costs = $this->lockCosts($lines);
        $movements = [];

        foreach ($lines as $line) {
            $cost = $costs[$line->productId];
            $fromStock = $this->branchStock($line->productId, $from);

            if (bccomp($line->quantity, (string) $fromStock->quantity_on_hand, self::SCALE) > 0) {
                throw StockRuleViolation::insufficientStock(
                    Product::query()->findOrFail($line->productId),
                    $from,
                    (string) $fromStock->quantity_on_hand,
                    $line->quantity,
                );
            }

            $toStock = $this->branchStock($line->productId, $to);

            $fromStock->forceFill([
                'quantity_on_hand' => bcsub((string) $fromStock->quantity_on_hand, $line->quantity, self::SCALE),
            ])->save();

            $toStock->forceFill([
                'quantity_on_hand' => bcadd((string) $toStock->quantity_on_hand, $line->quantity, self::SCALE),
            ])->save();

            $average = $this->average((string) $cost->total_value, (string) $cost->quantity_on_hand);

            $movements[$line->productId] = StockMovement::create([
                'product_id' => $line->productId,
                'branch_id' => $from->getKey(),
                'movement_date' => $date->format('Y-m-d'),
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'quantity' => bcmul($line->quantity, '-1', self::SCALE),
                'unit_cost' => $average,
                'value' => '0',
                'branch_qty_after' => (string) $fromStock->quantity_on_hand,
                'qty_after' => (string) $cost->quantity_on_hand,
                'value_after' => (string) $cost->total_value,
            ]);

            StockMovement::create([
                'product_id' => $line->productId,
                'branch_id' => $to->getKey(),
                'movement_date' => $date->format('Y-m-d'),
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'quantity' => $line->quantity,
                'unit_cost' => $average,
                'value' => '0',
                'branch_qty_after' => (string) $toStock->quantity_on_hand,
                'qty_after' => (string) $cost->quantity_on_hand,
                'value_after' => (string) $cost->total_value,
            ]);
        }

        return new StockResult($movements);
    }

    /**
     * Receive stock valued at the CURRENT average — goods-return credit
     * notes, where the only defensible cost is the running one: line-level
     * linkage to the original sale does not exist, and Qoyod values returns
     * the same way. The round-trip margin misstatement after an intervening
     * price move is the documented cost of that rule, not a bug.
     *
     * @param  list<StockLine>  $lines  value ignored; quantities positive
     * @return StockResult values = qty × average at currency scale
     */
    public function restock(
        Model $source,
        Branch $branch,
        DateTimeInterface $date,
        array $lines,
        int $currencyScale = 2,
    ): StockResult {
        $lines = $this->aggregate($lines);
        $costs = $this->lockCosts($lines);

        $valued = [];

        foreach ($lines as $line) {
            $cost = $costs[$line->productId];

            $average = $this->average((string) $cost->total_value, (string) $cost->quantity_on_hand);

            $valued[] = new StockLine(
                productId: $line->productId,
                quantity: $line->quantity,
                value: $this->roundToScale(
                    BigRational::of($average)->multipliedBy(BigRational::of($line->quantity)),
                    $currencyScale,
                ),
            );
        }

        return $this->receive($source, $branch, $date, $valued);
    }

    /**
     * The company's current average for a product — display and advisory
     * use only. No posting path may call this: posted figures come from
     * receive()/issue() under the lock.
     */
    public function currentAverage(Product $product): string
    {
        $cost = $product->costRecord;

        return $cost === null ? '0.0000' : (string) $cost->average_cost;
    }

    public function hasMovements(Product $product): bool
    {
        return StockMovement::query()
            ->where('product_id', $product->getKey())
            ->exists();
    }

    /**
     * Stamp the entry id onto movements once the ledger entry exists.
     *
     * @param  list<int>  $movementIds
     */
    public function stampEntry(array $movementIds, string $journalEntryId): void
    {
        if ($movementIds === []) {
            return;
        }

        StockMovement::query()
            ->whereIn('id', $movementIds)
            ->update(['journal_entry_id' => $journalEntryId]);
    }

    // -----------------------------------------------------------------------

    /**
     * Fold lines onto one row per product.
     *
     * A document may bill the same product on two lines; the shelf cares
     * about the product's total, and one movement per product keeps the
     * result map honest.
     *
     * @param  list<StockLine>  $lines
     * @return list<StockLine>
     */
    private function aggregate(array $lines): array
    {
        /** @var array<string, StockLine> $merged */
        $merged = [];

        foreach ($lines as $line) {
            $existing = $merged[$line->productId] ?? null;

            $merged[$line->productId] = $existing === null
                ? $line
                : new StockLine(
                    productId: $line->productId,
                    quantity: bcadd($existing->quantity, $line->quantity, self::SCALE),
                    value: bcadd((string) $existing->value, (string) $line->value, self::SCALE),
                );
        }

        return array_values($merged);
    }

    /**
     * Lock the cost rows for every product touched, in canonical order.
     *
     * @param  list<StockLine>  $lines
     * @return array<string, ProductCost>
     */
    private function lockCosts(array $lines): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (StockLine $line): string => $line->productId,
            $lines,
        )));

        sort($ids);

        $costs = ProductCost::query()
            ->whereIn('product_id', $ids)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ProductCost $cost): string => (string) $cost->product_id);

        foreach ($ids as $id) {
            if (! isset($costs[$id])) {
                // Enabling the flag creates the row; its absence means the
                // product was never tracked, and posting for it is a bug.
                throw StockRuleViolation::missingCostRow(Product::query()->findOrFail($id));
            }
        }

        return $costs->all();
    }

    /**
     * The branch stock row, created lazily under the cost-row lock — which
     * is what serializes creation and kills the lock-then-create race.
     */
    private function branchStock(string $productId, Branch $branch): ProductStock
    {
        return ProductStock::query()->firstOrCreate([
            'product_id' => $productId,
            'branch_id' => $branch->getKey(),
        ]);
    }

    private function writeMovement(
        Model $source,
        Branch $branch,
        DateTimeInterface $date,
        string $productId,
        string $quantity,
        string $value,
        ProductCost $cost,
        ProductStock $stock,
    ): StockMovement {
        $absQty = ltrim($quantity, '-');

        return StockMovement::create([
            'product_id' => $productId,
            'branch_id' => $branch->getKey(),
            'movement_date' => $date->format('Y-m-d'),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'quantity' => $quantity,
            'unit_cost' => bccomp($absQty, '0', self::SCALE) === 0
                ? '0'
                : bcdiv(ltrim($value, '-'), $absQty, self::SCALE),
            'value' => $value,
            'branch_qty_after' => (string) $stock->quantity_on_hand,
            'qty_after' => (string) $cost->quantity_on_hand,
            'value_after' => (string) $cost->total_value,
        ]);
    }

    /**
     * value / qty at scale 4 — never at zero or negative quantity, where
     * the average is undefined and reads as zero.
     */
    private function average(string $value, string $qty): string
    {
        if (bccomp($qty, '0', self::SCALE) <= 0) {
            return '0.0000';
        }

        return bcdiv($value, $qty, self::SCALE);
    }

    private function roundToScale(BigRational $amount, int $scale): string
    {
        return bcadd(
            (string) $amount->toScale($scale, RoundingMode::HalfUp),
            '0',
            self::SCALE,
        );
    }
}
