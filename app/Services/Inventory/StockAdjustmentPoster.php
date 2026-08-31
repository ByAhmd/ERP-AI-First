<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\DocumentStatus;
use App\Enums\StockAdjustmentKind;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\StockAdjustment;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\JournalPoster;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\Exceptions\AdjustmentRejected;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Approving a stock adjustment.
 *
 * Opening balances post DR المخزون / CR حساب الرصيد الافتتاحي — the one
 * bridge from a pre-inventory life into tracking. Count adjustments post
 * their increases DR المخزون / CR the offset account and their decreases
 * the other way, with the decrease valued at the running average resolved
 * at approval — never at whatever was typed.
 *
 * The offset defaults to تسويات المخزون for counts, a deliberate deviation
 * from Qoyod's revenue-for-surplus rule: a counting artifact is not income.
 */
final class StockAdjustmentPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly AccountRegistry $registry,
        private readonly DocumentNumberAllocator $numbers,
        private readonly StockLedger $stock,
    ) {}

    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'stock_adjustment',
            defaults: ['prefix' => 'ADJ-', 'padding' => 5],
        ));
    }

    public function approve(StockAdjustment $adjustment, ?string $userId = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $userId): StockAdjustment {
            $locked = StockAdjustment::query()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($locked);

            $offset = $this->offsetAccount($locked);

            $increases = [];
            $decreases = [];

            foreach ($locked->items()->get() as $item) {
                $change = (string) $item->quantity_change;

                if (bccomp($change, '0', self::SCALE) > 0) {
                    $value = (string) BigRational::of((string) $item->unit_cost)
                        ->multipliedBy(BigRational::of($change))
                        ->toScale(2, RoundingMode::HalfUp);

                    $increases[] = new StockLine((string) $item->product_id, $change, $value);
                } else {
                    $decreases[] = new StockLine((string) $item->product_id, bcmul($change, '-1', self::SCALE));
                }
            }

            $branch = $locked->branch()->firstOrFail();

            $lines = [];
            $movementIds = [];

            if ($increases !== []) {
                $result = $this->stock->receive($locked, $branch, $locked->adjustment_date, $increases);
                $movementIds = [...$movementIds, ...$result->movementIds()];

                $inValue = $result->totalValue();

                if (bccomp($inValue, '0', self::SCALE) !== 0) {
                    $lines[] = JournalLineData::debit(
                        $this->registry->get(SystemAccount::Inventory)->getKey(),
                        $inValue,
                    );
                    $lines[] = JournalLineData::credit($offset->getKey(), $inValue);
                }

                $this->stampResolved($locked, $result);
            }

            if ($decreases !== []) {
                $result = $this->stock->issue($locked, $branch, $locked->adjustment_date, $decreases);
                $movementIds = [...$movementIds, ...$result->movementIds()];

                $outValue = $result->totalValue();

                if (bccomp($outValue, '0', self::SCALE) !== 0) {
                    $lines[] = JournalLineData::debit($offset->getKey(), $outValue);
                    $lines[] = JournalLineData::credit(
                        $this->registry->get(SystemAccount::Inventory)->getKey(),
                        $outValue,
                    );
                }

                $this->stampResolved($locked, $result);
            }

            $entryId = null;

            // A wholly zero-value adjustment still moved quantity; only the
            // ledger has nothing to hear.
            if ($lines !== []) {
                $lines = array_map(
                    fn (JournalLineData $line): JournalLineData => $line->withBranch($locked->branch_id),
                    $lines,
                );

                $entry = $this->poster->post(
                    date: $locked->adjustment_date,
                    lines: $lines,
                    description: trim(__('inventory.adjustments.narration', [
                        'reference' => $locked->reference,
                    ])),
                    reference: $locked->reference,
                    source: $locked,
                    userId: $userId,
                );

                $entryId = $entry->getKey();
                $this->stock->stampEntry($movementIds, $entryId);
            }

            $locked->forceFill([
                'status' => DocumentStatus::Approved,
                'journal_entry_id' => $entryId,
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $locked->refresh();
        });
    }

    private function guard(StockAdjustment $adjustment): void
    {
        if ($adjustment->isApproved()) {
            throw AdjustmentRejected::alreadyApproved($adjustment);
        }

        if (! $adjustment->isDraft()) {
            throw AdjustmentRejected::notDraft();
        }

        $items = $adjustment->items()->get();

        if ($items->isEmpty()) {
            throw AdjustmentRejected::noItems();
        }

        foreach ($items as $item) {
            $change = (string) $item->quantity_change;

            if (bccomp($change, '0', self::SCALE) === 0) {
                throw AdjustmentRejected::zeroLine((int) $item->line_number);
            }

            if ($adjustment->kind === StockAdjustmentKind::Opening
                && bccomp($change, '0', self::SCALE) < 0) {
                throw AdjustmentRejected::openingNegative();
            }

            if (bccomp($change, '0', self::SCALE) > 0
                && ($item->unit_cost === null || bccomp((string) $item->unit_cost, '0', self::SCALE) < 0)) {
                throw AdjustmentRejected::costRequired((int) $item->line_number);
            }
        }
    }

    /**
     * The account the non-inventory side posts to.
     *
     * Openings are forced to the opening-balance suspense whatever the form
     * said; counts take the chosen account, defaulting to تسويات المخزون.
     */
    private function offsetAccount(StockAdjustment $adjustment): Account
    {
        if ($adjustment->kind === StockAdjustmentKind::Opening) {
            return $this->registry->get(SystemAccount::OpeningBalanceSuspense);
        }

        $account = $adjustment->offsetAccount;

        if ($account === null) {
            return $this->registry->get(SystemAccount::InventoryAdjustment);
        }

        if (! $account->acceptsPostings()) {
            throw StockRuleViolation::accountNotPostable($account);
        }

        return $account;
    }

    /**
     * Record what each line was actually valued at.
     */
    private function stampResolved(StockAdjustment $adjustment, Data\StockResult $result): void
    {
        foreach ($adjustment->items()->get() as $item) {
            $movement = $result->movements[(string) $item->product_id] ?? null;

            if ($movement === null) {
                continue;
            }

            $item->forceFill([
                'resolved_unit_cost' => (string) $movement->unit_cost,
                'value_change' => (string) $movement->value,
            ])->save();
        }
    }
}
