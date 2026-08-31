<?php

declare(strict_types=1);

namespace App\Services\Assets\Reports;

use App\Enums\FixedAssetStatus;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\FixedAssetType;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * The register–GL tie — the invariant the whole module defends.
 *
 * For every account referenced as an asset account by at least one type,
 * the GL balance must equal the summed cost of the ACTIVE assets on it; for
 * every accumulated account, the GL credit balance must equal the summed
 * opening-plus-posted accumulated of the same assets. Disposal maintains
 * both sides at once because it clears the posted snapshots.
 *
 * Three standing enemies, all detected here rather than blocked: balances
 * that predate the register (bridged by register-only openings), manual
 * journal entries against the asset accounts, and — blocked outright at the
 * ledger screen — reversals of asset-sourced entries.
 */
final class FixedAssetsTie
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, balanced: bool}
     */
    public function build(): array
    {
        $types = FixedAssetType::query()->get();

        $costAccounts = $types->pluck('asset_account_id')->unique()->values();
        $accumulatedAccounts = $types->pluck('accumulated_depreciation_account_id')->unique()->values();

        $rows = [];
        $balanced = true;

        foreach ($costAccounts as $accountId) {
            $gl = $this->glBalance((string) $accountId, creditNormal: false);
            $register = $this->registerCost((string) $accountId);

            $rows[] = $this->row('cost', (string) $accountId, $gl, $register, $balanced);
        }

        foreach ($accumulatedAccounts as $accountId) {
            $gl = $this->glBalance((string) $accountId, creditNormal: true);
            $register = $this->registerAccumulated((string) $accountId);

            $rows[] = $this->row('accumulated', (string) $accountId, $gl, $register, $balanced);
        }

        return ['rows' => $rows, 'balanced' => $balanced];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $role, string $accountId, string $gl, string $register, bool &$balanced): array
    {
        $difference = bcsub($gl, $register, self::SCALE);

        if (bccomp($difference, '0', self::SCALE) !== 0) {
            $balanced = false;
        }

        return [
            'role' => $role,
            'account' => Account::query()->find($accountId),
            'gl_balance' => $gl,
            'register_total' => $register,
            'difference' => $difference,
        ];
    }

    private function glBalance(string $accountId, bool $creditNormal): string
    {
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $this->context->idOrFail())
            ->where('e.company_id', $this->context->idOrFail())
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->where('l.account_id', $accountId)
            ->select([
                DB::raw('COALESCE(SUM(l.debit), 0) as debit_total'),
                DB::raw('COALESCE(SUM(l.credit), 0) as credit_total'),
            ])
            ->first();

        $debit = (string) ($row->debit_total ?? '0');
        $credit = (string) ($row->credit_total ?? '0');

        return $creditNormal
            ? bcsub($credit, $debit, self::SCALE)
            : bcsub($debit, $credit, self::SCALE);
    }

    private function registerCost(string $accountId): string
    {
        $companyId = $this->context->idOrFail();

        $total = DB::table('fixed_assets as fa')
            ->join('fixed_asset_types as t', 't.id', '=', 'fa.fixed_asset_type_id')
            ->where('fa.company_id', $companyId)
            ->where('t.company_id', $companyId)
            ->where('fa.status', FixedAssetStatus::Active->value)
            ->where('t.asset_account_id', $accountId)
            ->sum('fa.cost');

        return bcadd((string) ($total ?: '0'), '0', self::SCALE);
    }

    private function registerAccumulated(string $accountId): string
    {
        $companyId = $this->context->idOrFail();

        $opening = DB::table('fixed_assets as fa')
            ->join('fixed_asset_types as t', 't.id', '=', 'fa.fixed_asset_type_id')
            ->where('fa.company_id', $companyId)
            ->where('t.company_id', $companyId)
            ->where('fa.status', FixedAssetStatus::Active->value)
            ->where('t.accumulated_depreciation_account_id', $accountId)
            ->sum('fa.opening_accumulated_depreciation');

        $charges = DB::table('depreciation_charges as c')
            ->join('fixed_assets as fa', 'fa.id', '=', 'c.fixed_asset_id')
            ->join('fixed_asset_types as t', 't.id', '=', 'fa.fixed_asset_type_id')
            ->where('c.company_id', $companyId)
            ->where('fa.company_id', $companyId)
            ->where('t.company_id', $companyId)
            ->where('fa.status', FixedAssetStatus::Active->value)
            ->where('t.accumulated_depreciation_account_id', $accountId)
            ->sum('c.amount');

        return bcadd(
            bcadd((string) ($opening ?: '0'), '0', self::SCALE),
            bcadd((string) ($charges ?: '0'), '0', self::SCALE),
            self::SCALE,
        );
    }
}
