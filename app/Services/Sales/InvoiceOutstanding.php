<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\DocumentStatus;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Support\Tenancy\CompanyContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is still owed on an invoice.
 *
 * Three terms, not two: total, less approved credit notes, less allocations
 * from approved receipts. The third term is why this is one shared service —
 * the credit-note guard originally computed its own two-term remainder, which
 * was correct right up until receipts existed and then quietly wasn't. A
 * fully-paid invoice could be fully credited on top, driving that customer's
 * receivable negative inside a control account that carries no contact and so
 * can never show it. Two callers with two definitions of "outstanding" is how
 * that class of bug returns; this is the one definition.
 *
 * Every method takes an optional as-of date, and the date bound lives here
 * for the same reason: an aging report that wrote its own date-bounded
 * remainder would be a second definition waiting to drift. Null means now.
 * The credit-note term bounds on the note's issue date, which is the date its
 * entry posted at; the receipt term bounds on the ALLOCATION's effective
 * date — the receipt's own date when it was settled at approval, the
 * allocation entry's date when an advance was applied later. Bounding on the
 * receipt date alone would backdate July's advance-allocations into June.
 *
 * One caveat, deliberate: unallocating deletes the allocation row, so a
 * backdated figure reflects the CURRENT allocation state for allocations
 * released after the as-of date. The ledger is authoritative where the two
 * disagree.
 *
 * Draft documents count for nothing here. An abandoned draft receipt must not
 * make an invoice look paid — the allocation sum joins to the receipt's
 * status, always.
 *
 * Callers on a posting path must hold `lockForUpdate()` on the invoice before
 * asking, or two concurrent documents both read the same figure and both pass.
 */
final class InvoiceOutstanding
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    public function outstanding(SalesInvoice $invoice, ?DateTimeInterface $asOf = null): string
    {
        $afterCredits = bcsub(
            $this->scale((string) $invoice->total),
            $this->creditedOn($invoice, $asOf),
            self::SCALE,
        );

        return bcsub($afterCredits, $this->receivedOn($invoice, $asOf), self::SCALE);
    }

    /**
     * Approved credit notes against the invoice.
     */
    public function creditedOn(SalesInvoice $invoice, ?DateTimeInterface $asOf = null): string
    {
        $sum = SalesCreditNote::query()
            ->approved()
            ->where('parent_id', $invoice->getKey())
            ->when($asOf, fn ($q) => $q->where('issue_date', '<=', $asOf->format('Y-m-d')))
            ->sum('total');

        return $this->scale((string) $sum);
    }

    /**
     * Allocations from approved receipts against the invoice.
     */
    public function receivedOn(SalesInvoice $invoice, ?DateTimeInterface $asOf = null): string
    {
        $sum = $this->allocationQuery($asOf)
            ->where('a.sales_invoice_id', $invoice->getKey())
            ->where('a.company_id', $invoice->company_id)
            ->sum('a.amount');

        return $this->scale((string) $sum);
    }

    /**
     * Every customer's open-invoice position at a date, in one pass.
     *
     * The aging report's engine: one grouped query per term — totals, credit
     * notes, allocations — merged per invoice with bcmath, then folded per
     * contact. Set-based because the report renders this for every customer
     * on every filter change, and a per-invoice `outstanding()` loop would be
     * two queries a row. It lives here, beside the per-invoice definition,
     * so the two paths cannot drift; the parity test holds them equal.
     *
     * @return array<string, array{amount: string, count: int}>
     */
    public function openByContact(DateTimeInterface $asOf): array
    {
        $company = $this->context->idOrFail();
        $date = $asOf->format('Y-m-d');

        $invoices = DB::table('sales_invoices')
            ->where('company_id', $company)
            ->where('status', DocumentStatus::Approved->value)
            ->where('issue_date', '<=', $date)
            ->get(['id', 'contact_id', 'total']);

        $credited = DB::table('sales_credit_notes')
            ->where('company_id', $company)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('parent_id')
            ->where('issue_date', '<=', $date)
            ->groupBy('parent_id')
            ->selectRaw('parent_id, COALESCE(SUM(total), 0) as sum_total')
            ->pluck('sum_total', 'parent_id');

        $received = $this->allocationQuery($asOf)
            ->where('a.company_id', $company)
            ->groupBy('a.sales_invoice_id')
            ->selectRaw('a.sales_invoice_id, COALESCE(SUM(a.amount), 0) as sum_amount')
            ->pluck('sum_amount', 'a.sales_invoice_id');

        $result = [];

        foreach ($invoices as $invoice) {
            $remainder = bcsub(
                bcsub(
                    $this->scale((string) $invoice->total),
                    $this->scale((string) ($credited[$invoice->id] ?? '0')),
                    self::SCALE,
                ),
                $this->scale((string) ($received[$invoice->id] ?? '0')),
                self::SCALE,
            );

            if (bccomp($remainder, '0', self::SCALE) === 0) {
                continue;
            }

            $row = $result[$invoice->contact_id] ?? ['amount' => '0.0000', 'count' => 0];
            $row['amount'] = bcadd($row['amount'], $remainder, self::SCALE);
            $row['count']++;

            $result[$invoice->contact_id] = $row;
        }

        return $result;
    }

    /**
     * Approved credit notes with no parent invoice, as of a date.
     *
     * A standalone note credits receivable but reduces no invoice's
     * remainder, so the sum of open invoices exceeds the control account by
     * exactly this figure — the report's reconciliation line, not a grid row.
     */
    public function unappliedCreditNotesTotal(DateTimeInterface $asOf): string
    {
        $sum = DB::table('sales_credit_notes')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('parent_id')
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->sum('total');

        return $this->scale((string) $sum);
    }

    /**
     * Money received and not yet applied to any invoice, as of a date.
     *
     * The customer-advances liability, derived: approved receipts dated on or
     * before the date, less allocations effective by it. Deliberately absent
     * from the aging grid — the grid ties to receivable, and advances are a
     * different account — but shown beside it, because omitting the figure
     * entirely would overstate net exposure.
     */
    public function unallocatedAdvancesTotal(DateTimeInterface $asOf): string
    {
        $company = $this->context->idOrFail();
        $date = $asOf->format('Y-m-d');

        $received = DB::table('customer_receipts')
            ->where('company_id', $company)
            ->where('status', DocumentStatus::Approved->value)
            ->where('receipt_date', '<=', $date)
            ->sum('amount');

        $allocated = $this->allocationQuery($asOf)
            ->where('a.company_id', $company)
            ->sum('a.amount');

        return bcsub($this->scale((string) $received), $this->scale((string) $allocated), self::SCALE);
    }

    /**
     * Attach `amount_received` and `amount_credited` to an invoice list query.
     *
     * Two correlated subqueries rather than a sum per row in PHP, because the
     * list renders these for every invoice on every page. The same figures for
     * one invoice come from the methods above; the SQL and the methods must
     * agree, which is the other reason they live in one class.
     *
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function decorate(Builder $query): Builder
    {
        return $query
            ->addSelect([
                'amount_received' => DB::table('customer_receipt_allocations as a')
                    ->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')
                    ->whereColumn('a.sales_invoice_id', 'sales_invoices.id')
                    ->whereColumn('a.company_id', 'sales_invoices.company_id')
                    ->where('r.status', DocumentStatus::Approved->value)
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'amount_credited' => DB::table('sales_credit_notes as n')
                    ->whereColumn('n.parent_id', 'sales_invoices.id')
                    ->whereColumn('n.company_id', 'sales_invoices.company_id')
                    ->where('n.status', DocumentStatus::Approved->value)
                    ->selectRaw('COALESCE(SUM(n.total), 0)'),
            ]);
    }

    /**
     * Allocation rows from approved receipts, optionally date-bounded.
     *
     * The effective date is the allocation's own entry date when one exists —
     * an advance applied later — and the receipt's date otherwise, because
     * that is when the approval entry settled it. One place, because this
     * COALESCE is exactly the line a second implementation would get wrong.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function allocationQuery(?DateTimeInterface $asOf)
    {
        return DB::table('customer_receipt_allocations as a')
            ->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'a.journal_entry_id')
            ->where('r.status', DocumentStatus::Approved->value)
            ->when($asOf, fn ($q) => $q->whereRaw(
                'COALESCE(je.entry_date, r.receipt_date) <= ?',
                [$asOf->format('Y-m-d')],
            ));
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
