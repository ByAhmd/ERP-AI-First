<?php

declare(strict_types=1);

namespace App\Services\Sales\Reports;

use App\Enums\DocumentStatus;
use App\Services\Reports\AgingGrid;
use App\Services\Reports\AgingReportData;
use App\Services\Reports\ComparisonPeriods;
use App\Services\Sales\InvoiceOutstanding;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * تقرير أعمار ديون العملاء — customer receivables aging.
 *
 * One row per customer; one column per snapshot date; each cell the sum of
 * that customer's date-bounded invoice remainders with the count of open
 * invoices beside it. The remainder arithmetic lives in InvoiceOutstanding —
 * this service only orchestrates, so the report and the posting guards can
 * never disagree about what "outstanding" means.
 *
 * Driven by the invoice's ISSUE date, as Qoyod's supplier twin states
 * explicitly and the customer report is taken to mirror. Negative remainders
 * are shown, not clamped: the grid must tie to the receivable control, and a
 * prettier figure that does not reconcile is the worse report.
 */
final class CustomerAging
{
    public function __construct(
        private readonly InvoiceOutstanding $outstanding,
        private readonly CompanyContext $context,
    ) {}

    public function build(CarbonImmutable $asOf, ?string $unit = null, int $periods = 1): AgingReportData
    {
        $dates = ComparisonPeriods::dates($asOf, $unit, $periods);

        /** @var list<array<string, array{amount: string, count: int}>> $columns */
        $columns = array_map(
            fn (CarbonImmutable $date): array => $this->outstanding->openByContact($date),
            $dates,
        );

        return new AgingReportData(
            dates: $dates,
            rows: AgingGrid::rows($columns),
            totals: AgingGrid::totals($columns),
            foreignCount: $this->foreignCount($asOf),
        );
    }

    /**
     * The reconciliation lines under the grid, at the primary date: the
     * standalone credit notes the invoice rows cannot see, and the advances
     * held on a different account entirely.
     *
     * @return array{unapplied_notes: string, advances: string}
     */
    public function reconciliation(CarbonImmutable $asOf): array
    {
        return [
            'unapplied_notes' => $this->outstanding->unappliedCreditNotesTotal($asOf),
            'advances' => $this->outstanding->unallocatedAdvancesTotal($asOf),
        ];
    }

    private function foreignCount(CarbonImmutable $asOf): int
    {
        return (int) DB::table('sales_invoices')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', DocumentStatus::Approved->value)
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->whereNotNull('currency_id')
            ->count();
    }
}
