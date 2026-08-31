<?php

declare(strict_types=1);

namespace App\Services\Sales\Reports;

use App\Enums\QuotationStatus;
use App\Services\Reports\AgingGrid;
use App\Services\Reports\AgingReportData;
use App\Services\Reports\ComparisonPeriods;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * تقرير أعمار عروض الأسعار — quotation aging.
 *
 * The open pipeline: approved quotations not yet invoiced, at their full
 * tax-inclusive totals, keyed on the ISSUE date (Qoyod is explicit — تاريخ
 * الإصدار وليس تاريخ الإنشاء). The whitelist is the report: only
 * QuotationStatus::Approved counts, which excludes drafts, cancellations and
 * — the double-count trap — converted quotations, whose approved_at is still
 * set but whose status moved to Invoiced. Status is current state, so a
 * quotation invoiced in July disappears from every column including June's.
 * Expired-but-approved quotations stay in: expiry is derived from the clock,
 * and no scheduler rewrites history.
 */
final class QuotationAging
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
        $rows = DB::table('sales_quotations')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', QuotationStatus::Approved->value)
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
        return (int) DB::table('sales_quotations')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', QuotationStatus::Approved->value)
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->whereNotNull('currency_id')
            ->count();
    }
}
