<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Contact;
use App\Services\Purchases\BillOutstanding;
use App\Services\Sales\InvoiceOutstanding;
use Carbon\CarbonImmutable;

/**
 * تقرير أعمار الديون — the unified day-bucket debt aging report.
 *
 * Customers and suppliers in one report, bucketed by how long each open
 * document has been due: حالية، من 1 إلى 30 يوم، من 31 إلى 60، من 61 إلى
 * 90، أكثر من 90. The bucket basis is the DUE date where one exists and the
 * issue date where it does not — simple bills carry no due date, and a null
 * that fell through the arithmetic would file a ninety-day-old bill under
 * "current" forever.
 *
 * The boundary rule, stated once: days = as-of date − effective date, as
 * date-only integers. Zero or negative days is current; day 30 is still the
 * first bucket and day 31 the second. The delay column is the same figure
 * signed — Qoyod's أيام التأخير, negative for not-yet-due.
 *
 * Remainders come from the two outstanding services' per-document paths, so
 * this report shares the one definition of "outstanding" with everything
 * else that answers the question.
 */
final class DebtAging
{
    private const SCALE = 4;

    public function __construct(
        private readonly InvoiceOutstanding $receivables,
        private readonly BillOutstanding $payables,
    ) {}

    /**
     * @param  'customer'|'vendor'|null  $contactType
     */
    public function build(
        CarbonImmutable $asOf,
        ?string $contactType = null,
        ?string $contactId = null,
        string $minAmount = '0',
        string $view = 'summary',
    ): DebtAgingData {
        $documents = [];

        if ($contactType !== 'vendor') {
            foreach ($this->receivables->openInvoices($asOf) as $row) {
                $row['type'] = 'customer';
                $documents[] = $row;
            }
        }

        if ($contactType !== 'customer') {
            foreach ($this->payables->openInvoices($asOf) as $row) {
                $row['type'] = 'vendor';
                $documents[] = $row;
            }
        }

        if ($contactId !== null) {
            $documents = array_values(array_filter(
                $documents,
                static fn (array $row): bool => $row['contact_id'] === $contactId,
            ));
        }

        $contacts = $this->contacts($documents);

        return $view === 'details'
            ? $this->detailsView($documents, $contacts, $asOf, $minAmount)
            : $this->summaryView($documents, $contacts, $asOf, $minAmount);
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @param  array<string, Contact>  $contacts
     */
    private function summaryView(array $documents, array $contacts, CarbonImmutable $asOf, string $minAmount): DebtAgingData
    {
        /** @var array<string, array{type: string, buckets: array<string, string>, total: string}> $byContact */
        $byContact = [];

        foreach ($documents as $document) {
            $bucket = $this->bucketFor($this->delayDays($document, $asOf));

            $row = $byContact[$document['contact_id']] ?? [
                'type' => $document['type'],
                'buckets' => array_fill_keys(DebtAgingData::BUCKETS, '0.0000'),
                'total' => '0.0000',
            ];

            $row['buckets'][$bucket] = bcadd($row['buckets'][$bucket], $document['remainder'], self::SCALE);
            $row['total'] = bcadd($row['total'], $document['remainder'], self::SCALE);

            $byContact[$document['contact_id']] = $row;
        }

        $rows = [];

        foreach ($byContact as $id => $row) {
            if (bccomp($row['total'], $this->scale($minAmount), self::SCALE) < 0) {
                continue;
            }

            $contact = $contacts[$id] ?? null;

            $rows[] = new DebtAgingSummaryRow(
                contactId: (string) $id,
                name: $contact?->displayName() ?? '—',
                type: $row['type'],
                buckets: $row['buckets'],
                total: $row['total'],
            );
        }

        usort($rows, static fn (DebtAgingSummaryRow $a, DebtAgingSummaryRow $b): int => strcmp($a->name, $b->name));

        $totals = array_fill_keys(DebtAgingData::BUCKETS, '0.0000');
        $totals['total'] = '0.0000';

        foreach ($rows as $row) {
            foreach (DebtAgingData::BUCKETS as $bucket) {
                $totals[$bucket] = bcadd($totals[$bucket], $row->buckets[$bucket], self::SCALE);
            }

            $totals['total'] = bcadd($totals['total'], $row->total, self::SCALE);
        }

        return new DebtAgingData(summary: $rows, details: [], totals: $totals);
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @param  array<string, Contact>  $contacts
     */
    private function detailsView(array $documents, array $contacts, CarbonImmutable $asOf, string $minAmount): DebtAgingData
    {
        $rows = [];
        $total = '0.0000';

        foreach ($documents as $document) {
            if (bccomp((string) $document['remainder'], $this->scale($minAmount), self::SCALE) < 0) {
                continue;
            }

            $contact = $contacts[$document['contact_id']] ?? null;

            $rows[] = new DebtAgingDetailRow(
                reference: (string) $document['reference'],
                documentType: $document['type'] === 'customer' ? 'invoice' : 'bill',
                issueDate: (string) $document['issue_date'],
                dueDate: $document['due_date'] === null ? null : (string) $document['due_date'],
                contactName: $contact?->displayName() ?? '—',
                remainder: (string) $document['remainder'],
                delayDays: $this->delayDays($document, $asOf),
            );

            $total = bcadd($total, (string) $document['remainder'], self::SCALE);
        }

        usort($rows, static fn (DebtAgingDetailRow $a, DebtAgingDetailRow $b): int => $b->delayDays <=> $a->delayDays);

        return new DebtAgingData(summary: [], details: $rows, totals: ['total' => $total]);
    }

    /**
     * The signed delay: as-of minus the effective date, in whole days.
     *
     * @param  array<string, mixed>  $document
     */
    private function delayDays(array $document, CarbonImmutable $asOf): int
    {
        $effective = CarbonImmutable::parse((string) ($document['due_date'] ?? $document['issue_date']));

        // Signed: positive when the as-of date is past the effective date.
        return (int) $effective->startOfDay()->diffInDays($asOf->startOfDay(), false);
    }

    private function bucketFor(int $days): string
    {
        return match (true) {
            $days <= 0 => 'current',
            $days <= 30 => 'b1_30',
            $days <= 60 => 'b31_60',
            $days <= 90 => 'b61_90',
            default => 'over_90',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, Contact>
     */
    private function contacts(array $documents): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['contact_id'],
            $documents,
        )));

        if ($ids === []) {
            return [];
        }

        return Contact::query()
            ->withTrashed()
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (Contact $contact): string => $contact->getKey())
            ->all();
    }

    private function scale(string $amount): string
    {
        return bcadd(trim($amount) === '' ? '0' : trim($amount), '0', self::SCALE);
    }
}
