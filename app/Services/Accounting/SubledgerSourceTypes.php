<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\DepreciationRun;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceSettlement;
use App\Models\EmployeeBonus;
use App\Models\EmployeePaymentVoucher;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\PayrollRun;
use App\Services\Inventory\StockLedger;
use Illuminate\Database\Eloquent\Model;

/**
 * Source types whose ledger entries belong to a subledger.
 *
 * The ledger screen's reverse action must never touch these: a ledger-side
 * reversal would restore the money and leave the subledger — stock
 * quantities, depreciation charges, disposal snapshots — untouched, and the
 * two would disagree forever with no exception thrown.
 *
 * ONE list, deliberately: the inventory slice kept its own constant, the
 * assets slice adds more, and a third module extending a second list is
 * exactly how one of them gets forgotten. Each subledger keeps its constant
 * where its semantics live; the union lives here alone.
 */
final class SubledgerSourceTypes
{
    /**
     * @return list<class-string<Model>>
     */
    public static function all(): array
    {
        return [
            ...StockLedger::STOCK_SOURCE_TYPES,
            DepreciationRun::class,
            FixedAssetDisposal::class,
            FixedAsset::class,
            PayrollRun::class,
            EmployeeBonus::class,
            EmployeeAdvance::class,
            EmployeeAdvanceSettlement::class,
            EmployeePaymentVoucher::class,
        ];
    }

    public static function contains(?string $sourceType): bool
    {
        return $sourceType !== null
            && in_array($sourceType, self::all(), true);
    }
}
