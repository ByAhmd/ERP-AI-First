<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\DocumentStatus;
use App\Models\PurchaseInvoice;
use App\Support\Tenancy\CompanyContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is still owed on a purchase invoice.
 *
 * Three terms from its first commit: total, less approved debit notes, less
 * allocations from approved payment vouchers — the sales side documented what
 * a two-term interim does, and this mirror refuses to repeat it.
 *
 * Every method takes an optional as-of date, held here so no report can grow
 * a second definition of "outstanding". The debit-note term bounds on the
 * note's issue date — the date its entry posted at. The payment term bounds
 * on the ALLOCATION's effective date: the voucher's date when settled at
 * approval, the allocation entry's own date when an advance was applied
 * later. Unallocation deletes its row, so a backdated figure reflects the
 * current allocation state for allocations released after the date; the
 * ledger is authoritative where they disagree.
 *
 * Draft documents count for nothing. Callers on a posting path must hold
 * `lockForUpdate()` on the bill before asking.
 */
final class BillOutstanding
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    public function outstanding(PurchaseInvoice $invoice, ?DateTimeInterface $asOf = null): string
    {
        $afterDebits = bcsub(
            $this->scale((string) $invoice->total),
            $this->debitedOn($invoice, $asOf),
            self::SCALE,
        );

        return bcsub($afterDebits, $this->paidOn($invoice, $asOf), self::SCALE);
    }

    /**
     * Approved debit notes against the bill.
     */
    public function debitedOn(PurchaseInvoice $invoice, ?DateTimeInterface $asOf = null): string
    {
        $sum = DB::table('purchase_debit_notes')
            ->where('company_id', $invoice->company_id)
            ->where('status', DocumentStatus::Approved->value)
            ->where('parent_id', $invoice->getKey())
            ->when($asOf, fn ($q) => $q->where('issue_date', '<=', $asOf->format('Y-m-d')))
            ->sum('total');

        return $this->scale((string) $sum);
    }

    /**
     * Allocations from approved payment vouchers against the bill.
     */
    public function paidOn(PurchaseInvoice $invoice, ?DateTimeInterface $asOf = null): string
    {
        $sum = $this->allocationQuery($asOf)
            ->where('a.purchase_invoice_id', $invoice->getKey())
            ->where('a.company_id', $invoice->company_id)
            ->sum('a.amount');

        return $this->scale((string) $sum);
    }

    /**
     * Every supplier's open-bill position at a date, in one pass.
     *
     * Both kinds of bill — the standard and the simple — because both credit
     * payables through the one poster, and the shared table exists precisely
     * so this query needs no UNION.
     *
     * @return array<string, array{amount: string, count: int}>
     */
    public function openByContact(DateTimeInterface $asOf): array
    {
        $result = [];

        foreach ($this->openInvoices($asOf) as $invoice) {
            $row = $result[$invoice['contact_id']] ?? ['amount' => '0.0000', 'count' => 0];
            $row['amount'] = bcadd($row['amount'], $invoice['remainder'], self::SCALE);
            $row['count']++;

            $result[$invoice['contact_id']] = $row;
        }

        return $result;
    }

    /**
     * Every open bill at a date, one row per document — both kinds.
     *
     * The per-document face of the same computation; the contact fold above
     * and the day-bucket report both consume it, so the remainder is derived
     * in exactly one place. Simple bills carry no due date — the day-bucket
     * report falls back to the issue date, and the null travels through here
     * untouched so that rule lives with the report that owns it.
     *
     * @return list<array{id: string, contact_id: string, reference: string,
     *     issue_date: string, due_date: ?string, remainder: string}>
     */
    public function openInvoices(DateTimeInterface $asOf): array
    {
        $company = $this->context->idOrFail();
        $date = $asOf->format('Y-m-d');

        $invoices = DB::table('purchase_invoices')
            ->where('company_id', $company)
            ->where('status', DocumentStatus::Approved->value)
            ->where('issue_date', '<=', $date)
            ->get(['id', 'contact_id', 'reference', 'issue_date', 'due_date', 'total']);

        $debited = DB::table('purchase_debit_notes')
            ->where('company_id', $company)
            ->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('parent_id')
            ->where('issue_date', '<=', $date)
            ->groupBy('parent_id')
            ->selectRaw('parent_id, COALESCE(SUM(total), 0) as sum_total')
            ->pluck('sum_total', 'parent_id');

        $paid = $this->allocationQuery($asOf)
            ->where('a.company_id', $company)
            ->groupBy('a.purchase_invoice_id')
            ->selectRaw('a.purchase_invoice_id, COALESCE(SUM(a.amount), 0) as sum_amount')
            ->pluck('sum_amount', 'a.purchase_invoice_id');

        $result = [];

        foreach ($invoices as $invoice) {
            $remainder = bcsub(
                bcsub(
                    $this->scale((string) $invoice->total),
                    $this->scale((string) ($debited[$invoice->id] ?? '0')),
                    self::SCALE,
                ),
                $this->scale((string) ($paid[$invoice->id] ?? '0')),
                self::SCALE,
            );

            if (bccomp($remainder, '0', self::SCALE) === 0) {
                continue;
            }

            $result[] = [
                'id' => (string) $invoice->id,
                'contact_id' => (string) $invoice->contact_id,
                'reference' => (string) $invoice->reference,
                'issue_date' => (string) $invoice->issue_date,
                'due_date' => $invoice->due_date === null ? null : (string) $invoice->due_date,
                'remainder' => $remainder,
            ];
        }

        return $result;
    }

    /**
     * Approved debit notes with no parent bill, as of a date — the
     * reconciliation line between the open-bill sum and the payables control.
     */
    public function unappliedDebitNotesTotal(DateTimeInterface $asOf): string
    {
        $sum = DB::table('purchase_debit_notes')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('parent_id')
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->sum('total');

        return $this->scale((string) $sum);
    }

    /**
     * Money paid and not yet applied to any bill, as of a date.
     *
     * The supplier-advances ASSET, derived — our money held by suppliers.
     * Beside the grid, never inside it: the grid ties to payables.
     */
    public function unallocatedAdvancesTotal(DateTimeInterface $asOf): string
    {
        $company = $this->context->idOrFail();
        $date = $asOf->format('Y-m-d');

        $paid = DB::table('supplier_payments')
            ->where('company_id', $company)
            ->where('status', DocumentStatus::Approved->value)
            ->where('payment_date', '<=', $date)
            ->sum('amount');

        $allocated = $this->allocationQuery($asOf)
            ->where('a.company_id', $company)
            ->sum('a.amount');

        return bcsub($this->scale((string) $paid), $this->scale((string) $allocated), self::SCALE);
    }

    /**
     * Standalone approved debit notes per contact, as of a date.
     *
     * @return array<string, string>
     */
    public function unappliedNotesByContact(DateTimeInterface $asOf): array
    {
        return DB::table('purchase_debit_notes')
            ->where('company_id', $this->context->idOrFail())
            ->where('status', DocumentStatus::Approved->value)
            ->whereNull('parent_id')
            ->where('issue_date', '<=', $asOf->format('Y-m-d'))
            ->groupBy('contact_id')
            ->selectRaw('contact_id, COALESCE(SUM(total), 0) as sum_total')
            ->pluck('sum_total', 'contact_id')
            ->map(fn ($sum): string => $this->scale((string) $sum))
            ->all();
    }

    /**
     * Each supplier's unallocated advance, as of a date — the asset side.
     *
     * @return array<string, string>
     */
    public function advancesByContact(DateTimeInterface $asOf): array
    {
        $company = $this->context->idOrFail();
        $date = $asOf->format('Y-m-d');

        $paid = DB::table('supplier_payments')
            ->where('company_id', $company)
            ->where('status', DocumentStatus::Approved->value)
            ->where('payment_date', '<=', $date)
            ->groupBy('contact_id')
            ->selectRaw('contact_id, COALESCE(SUM(amount), 0) as sum_amount')
            ->pluck('sum_amount', 'contact_id');

        $allocated = $this->allocationQuery($asOf)
            ->where('a.company_id', $company)
            ->groupBy('p.contact_id')
            ->selectRaw('p.contact_id, COALESCE(SUM(a.amount), 0) as sum_amount')
            ->pluck('sum_amount', 'p.contact_id');

        $result = [];

        foreach ($paid as $contactId => $sum) {
            $advance = bcsub(
                $this->scale((string) $sum),
                $this->scale((string) ($allocated[$contactId] ?? '0')),
                self::SCALE,
            );

            if (bccomp($advance, '0', self::SCALE) !== 0) {
                $result[$contactId] = $advance;
            }
        }

        return $result;
    }

    /**
     * Attach `amount_paid` and `amount_debited` to a bill list query.
     *
     * @param  Builder<PurchaseInvoice>  $query
     * @return Builder<PurchaseInvoice>
     */
    public function decorate(Builder $query): Builder
    {
        return $query
            ->addSelect([
                'amount_paid' => DB::table('supplier_payment_allocations as a')
                    ->join('supplier_payments as p', 'p.id', '=', 'a.supplier_payment_id')
                    ->whereColumn('a.purchase_invoice_id', 'purchase_invoices.id')
                    ->whereColumn('a.company_id', 'purchase_invoices.company_id')
                    ->where('p.status', DocumentStatus::Approved->value)
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'amount_debited' => DB::table('purchase_debit_notes as n')
                    ->whereColumn('n.parent_id', 'purchase_invoices.id')
                    ->whereColumn('n.company_id', 'purchase_invoices.company_id')
                    ->where('n.status', DocumentStatus::Approved->value)
                    ->selectRaw('COALESCE(SUM(n.total), 0)'),
            ]);
    }

    /**
     * Allocation rows from approved vouchers, optionally date-bounded on the
     * allocation's effective date — the voucher's date, unless a later
     * advance movement gave the allocation an entry of its own.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function allocationQuery(?DateTimeInterface $asOf)
    {
        return DB::table('supplier_payment_allocations as a')
            ->join('supplier_payments as p', 'p.id', '=', 'a.supplier_payment_id')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'a.journal_entry_id')
            ->where('p.status', DocumentStatus::Approved->value)
            ->when($asOf, fn ($q) => $q->whereRaw(
                'COALESCE(je.entry_date, p.payment_date) <= ?',
                [$asOf->format('Y-m-d')],
            ));
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
