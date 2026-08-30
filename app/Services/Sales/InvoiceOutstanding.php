<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\DocumentStatus;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
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

    public function outstanding(SalesInvoice $invoice): string
    {
        $afterCredits = bcsub(
            $this->scale((string) $invoice->total),
            $this->creditedOn($invoice),
            self::SCALE,
        );

        return bcsub($afterCredits, $this->receivedOn($invoice), self::SCALE);
    }

    /**
     * Approved credit notes against the invoice.
     */
    public function creditedOn(SalesInvoice $invoice): string
    {
        $sum = SalesCreditNote::query()
            ->approved()
            ->where('parent_id', $invoice->getKey())
            ->sum('total');

        return $this->scale((string) $sum);
    }

    /**
     * Allocations from approved receipts against the invoice.
     */
    public function receivedOn(SalesInvoice $invoice): string
    {
        $sum = DB::table('customer_receipt_allocations as a')
            ->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')
            ->where('a.sales_invoice_id', $invoice->getKey())
            ->where('a.company_id', $invoice->company_id)
            ->where('r.status', DocumentStatus::Approved->value)
            ->sum('a.amount');

        return $this->scale((string) $sum);
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
                    ->where('r.status', DocumentStatus::Approved->value)
                    ->selectRaw('COALESCE(SUM(a.amount), 0)'),
                'amount_credited' => DB::table('sales_credit_notes as n')
                    ->whereColumn('n.parent_id', 'sales_invoices.id')
                    ->where('n.status', DocumentStatus::Approved->value)
                    ->selectRaw('COALESCE(SUM(n.total), 0)'),
            ]);
    }

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
