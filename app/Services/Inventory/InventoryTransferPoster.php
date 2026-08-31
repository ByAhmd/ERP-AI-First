<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\DocumentStatus;
use App\Models\InventoryTransfer;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Inventory\Data\StockLine;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use App\Services\Inventory\Exceptions\TransferRejected;
use Illuminate\Support\Facades\DB;

/**
 * Approving an inventory transfer.
 *
 * Qoyod's إرسال واستقبال in one step: the quantities leave the source
 * branch and arrive at the destination inside one transaction, at the
 * company-wide average, with no ledger entry — one inventory account means
 * the net effect is zero. The إرسال-only in-transit state ships with
 * per-location inventory accounts, and this poster is where it will land.
 */
final class InventoryTransferPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly DocumentNumberAllocator $numbers,
        private readonly StockLedger $stock,
    ) {}

    public function nextReference(): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(
            key: 'inventory_transfer',
            defaults: ['prefix' => 'TRF-', 'padding' => 5],
        ));
    }

    public function approve(InventoryTransfer $transfer, ?string $userId = null): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): InventoryTransfer {
            $locked = InventoryTransfer::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($locked);

            $lines = [];

            foreach ($locked->items()->get() as $item) {
                $lines[] = new StockLine(
                    productId: (string) $item->product_id,
                    quantity: (string) $item->quantity,
                );
            }

            $this->stock->transfer(
                $locked,
                $locked->fromBranch()->firstOrFail(),
                $locked->toBranch()->firstOrFail(),
                $locked->transfer_date,
                $lines,
            );

            $locked->forceFill([
                'status' => DocumentStatus::Approved,
                'approved_at' => now(),
                'approved_by_id' => $userId,
            ])->save();

            return $locked->refresh();
        });
    }

    private function guard(InventoryTransfer $transfer): void
    {
        if ($transfer->isApproved()) {
            throw TransferRejected::alreadyApproved($transfer);
        }

        if (! $transfer->isDraft()) {
            throw TransferRejected::notDraft();
        }

        if ($transfer->from_branch_id === $transfer->to_branch_id) {
            throw TransferRejected::sameBranch();
        }

        $items = $transfer->items()->get();

        if ($items->isEmpty()) {
            throw TransferRejected::noItems();
        }

        foreach ($items as $item) {
            if (bccomp((string) $item->quantity, '0', self::SCALE) <= 0) {
                throw TransferRejected::zeroLine((int) $item->line_number);
            }

            $product = $item->product;

            if ($product === null || ! $product->isStocked()) {
                throw StockRuleViolation::notTracked(
                    $product ?? $item->product()->withTrashed()->firstOrFail(),
                );
            }
        }
    }
}
