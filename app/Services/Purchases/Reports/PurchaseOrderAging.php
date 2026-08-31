<?php

declare(strict_types=1);

namespace App\Services\Purchases\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Services\Reports\AgingGrid;
use App\Services\Reports\AgingReportData;
use App\Services\Reports\ComparisonPeriods;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * تقرير أعمار أوامر الشراء — purchase order aging.
 *
 * The quotation report's mirror: approved orders not yet billed, full
 * tax-inclusive totals, keyed on the issue date. The status whitelist
 * excludes drafts, cancellations, and billed orders — an order's approved_at
 * survives conversion, so filtering on it instead of the status would count
 * the order and its bill both. Overdue-but-approved orders stay in.
 */
final class PurchaseOrderAging
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    public function build(CarbonImmutable $asOf, ?string $unit = null, int $periods = 1): AgingReportData
    {
        $dates = ComparisonPeriods::dates($asOf, $unit, $periods);

        $columns = array_map(fn (CarbonImmutable $date): array => $this->openByContact($date), $dates);

        return new AgingReportData(
            dates: $dates,
            rows: AgingGrid::rows($columns),
            totals: AgingGrid::totals($columns),
            foreignCount: $this->foreignCount($asOf),
        );
    }

    /**
     * @return array<string, array{amount: string, count: int}>
     */
    private function openByContact(CarbonImmutable $asOf): array
    {
        $rows = DB::table('purchase_orders')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', PurchaseOrderStatus::Approved->value)
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->groupBy('contact_id')
            ->selectRaw('contact_id, COALESCE(SUM(total), 0) as sum_total, COUNT(*) as doc_count')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->contact_id] = [
                'amount' => bcadd((string) $row->sum_total, '0', self::SCALE),
                'count' => (int) $row->doc_count,
            ];
        }

        return $result;
    }

    private function foreignCount(CarbonImmutable $asOf): int
    {
        return (int) DB::table('purchase_orders')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', PurchaseOrderStatus::Approved->value)
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->whereNotNull('currency_id')
            ->count();
    }
}
