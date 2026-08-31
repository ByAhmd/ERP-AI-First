<?php

declare(strict_types=1);

namespace App\Services\Inventory\Exceptions;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Product;
use RuntimeException;

/**
 * A stock movement the ledger cannot accept.
 *
 * Each of these protects the one invariant the whole slice defends: the
 * inventory control account and the stock subledger move by the same number,
 * always.
 */
final class StockRuleViolation extends RuntimeException
{
    /**
     * Qoyod's «الكمية غير متوفرة» — the refusal that also makes a poisoned
     * negative average unrepresentable.
     */
    public static function insufficientStock(
        Product $product,
        Branch $branch,
        string $available,
        string $requested,
    ): self {
        return new self(__('inventory.errors.insufficient_stock', [
            'product' => $product->name,
            'branch' => $branch->displayName(),
            'available' => $available,
            'requested' => $requested,
        ]));
    }

    public static function missingCostRow(Product $product): self
    {
        return new self(__('inventory.errors.missing_cost_row', [
            'product' => $product->name,
        ]));
    }

    public static function branchRequired(): self
    {
        return new self(__('inventory.errors.branch_required'));
    }

    public static function accountNotPostable(Account $account): self
    {
        return new self(__('inventory.errors.account_not_postable', [
            'account' => $account->code.' - '.$account->name,
        ]));
    }

    public static function notTracked(Product $product): self
    {
        return new self(__('inventory.errors.not_tracked', [
            'product' => $product->name,
        ]));
    }

    public static function costRequired(Product $product): self
    {
        return new self(__('inventory.errors.cost_required', [
            'product' => $product->name,
        ]));
    }
}
