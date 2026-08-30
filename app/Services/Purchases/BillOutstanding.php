<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\DocumentStatus;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is still owed on a purchase invoice.
 *
 * Three terms from its first commit: total, less approved debit notes, less
 * allocations from approved payment vouchers. The payments table exists one
 * slice before its screens do precisely so this can be true — the sales side
 * documented what happens otherwise: a two-term interim that was correct
 * until payments existed and then quietly wasn't, letting a fully-paid bill
 * be fully debited on top and driving the supplier's payable negative inside
 * a control account that carries no contact and can never show it.
 *
 * Draft documents count for nothing. Callers on a posting path must hold
 * `lockForUpdate()` on the bill before asking.
 */
final class BillOutstanding
{
    private const SCALE = 4;

    public function outstanding(PurchaseInvoice $invoice): string
    {
        $afterDebits = bcsub(
            $this->scale((string) $invoice->total),
            $this->debitedOn($invoice),
            self::SCALE,
        );

        return bcsub($afterDebits, $this->paidOn($invoice), self::SCALE);
    }

    /**
     * Approved debit notes against the bill.
     */
    public function debitedOn(PurchaseInvoice $invoice): string
    {
        $sum = PurchaseDebitNote::query()
            ->approved()
            ->where('parent_id', $invoice->getKey())
            ->sum('total');

        return $this->scale((string) $sum);
    }

    /**
     * Allocations from approved payment vouchers against the bill.
     */
    public function paidOn(PurchaseInvoice $invoice): string
    {
        $sum = DB::table('supplier_payment_allocations as a')
            ->join('supplier_payments as p', 'p.id', '=', 'a.supplier_payment_id')
            ->where('a.purchase_invoice_id', $invoice->getKey())
            ->where('a.company_id', $invoice->company_id)
            ->where('p.status', DocumentStatus::Approved->value)
            ->sum('a.amount');

        return $this->scale((string) $sum);
    }

    /**
     * Attach `amount_paid` and `amount_debited` to a bill list query.
     *
     * The sales mirror filtered company on the method but not on these
     * subqueries; here the filter is written in both places — the safer form
     * — so the two paths cannot drift apart on a tenancy edge.
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

    private function scale(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }
}
