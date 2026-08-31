<?php

declare(strict_types=1);

namespace App\Services\Purchases\Reports;

use App\Enums\DocumentStatus;
use App\Services\Purchases\BillOutstanding;
use App\Services\Reports\AgingGrid;
use App\Services\Reports\AgingReportData;
use App\Services\Reports\ComparisonPeriods;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * تقرير أعمار ديون الموردين — supplier payables aging.
 *
 * The mirror of the customer report on the payables side, driven by the
 * bill's issue date (Qoyod states this explicitly for this report), summing
 * date-bounded bill remainders through BillOutstanding — both kinds of bill,
 * because both credit payables.
 */
final class SupplierAging
{
    public function __construct(
        private readonly BillOutstanding $outstanding,
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
     * @return array{unapplied_notes: string, advances: string}
     */
    public function reconciliation(CarbonImmutable $asOf): array
    {
        return [
            'unapplied_notes' => $this->outstanding->unappliedDebitNotesTotal($asOf),
            'advances' => $this->outstanding->unallocatedAdvancesTotal($asOf),
        ];
    }

    private function foreignCount(CarbonImmutable $asOf): int
    {
        return (int) DB::table('purchase_invoices')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', DocumentStatus::Approved->value)
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->whereNotNull('currency_id')
            ->count();
    }
}
