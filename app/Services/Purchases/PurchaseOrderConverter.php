<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceKind;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemAccount;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\Exceptions\PurchaseOrderRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Turning an approved purchase order into a draft bill — تحويل لفاتورة.
 *
 * The quotation converter's split, on the buy side:
 *
 * Carried verbatim — the commercial agreement: the supplier, the products,
 * the quantities, the AGREED prices, the discounts. Re-resolving a price
 * from the product would silently renege on what was negotiated.
 *
 * Re-resolved at conversion — the fiscal facts: the bill's own BIL
 * reference, its dates, and every tax rate at the bill's own date. The
 * expense account is resolved here too — it is a property of the bill, not
 * the order, so it comes from the product's current pointer with cost of
 * goods sold as the fallback.
 *
 * One order, one bill. The unique index on purchase_invoices decides the
 * race below this service; the row lock decides it inside. Expiry does not
 * block — the overdue order converts with a UI warning, the mitigation
 * being the editable draft a human reviews.
 */
final class PurchaseOrderConverter
{
    public function __construct(
        private readonly PurchaseInvoicePoster $invoices,
        private readonly PurchaseInvoiceRecalculator $recalculator,
        private readonly AccountRegistry $registry,
    ) {}

    public function convert(PurchaseOrder $order, ?string $userId = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($order, $userId): PurchaseInvoice {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guard($locked);

            $invoice = PurchaseInvoice::query()->create([
                'company_id' => $locked->company_id,
                // Drawn from the bill series now, never derived from ORD.
                'reference' => $this->invoices->nextReference(),
                'kind' => PurchaseInvoiceKind::Standard,
                'status' => DocumentStatus::Draft,
                'contact_id' => $locked->contact_id,
                'purchase_order_id' => $locked->getKey(),
                'issue_date' => today(),
                'due_date' => today(),
                'description' => $locked->description,
                'terms_and_conditions' => $locked->terms_and_conditions,
                'notes' => $locked->notes,
                'currency_id' => $locked->currency_id,
                'created_by_id' => $userId,
            ]);

            $fallbackAccount = $this->registry->get(SystemAccount::CostOfGoodsSold)->getKey();

            foreach ($locked->items()->get() as $line) {
                // Raw inputs only — every derived column stays at its default
                // for the recalculator to resolve at today's law.
                $invoice->items()->create([
                    'company_id' => $locked->company_id,
                    'line_number' => $line->line_number,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product_name,
                    'product_description' => $line->product_description,
                    'unit_name' => $line->unit_name,
                    'expense_account_id' => $this->expenseAccountFor($line->product_id, $fallbackAccount),
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'is_inclusive' => $line->is_inclusive,
                    'discount_value' => (string) $line->discount_value,
                    'discount_type' => $line->discount_type,
                    'tax_id' => $line->tax_id,
                ]);
            }

            $invoice = $this->recalculator->recalculate($invoice);

            $locked->forceFill(['status' => PurchaseOrderStatus::Billed])->save();

            return $invoice;
        });
    }

    /**
     * The debit side of a converted line.
     *
     * A property of the bill, resolved at conversion from the product's
     * current pointer — unlike the price, which is the agreement and is
     * carried verbatim. A product-less line falls back to cost of goods
     * sold; the clerk edits the draft if it belongs elsewhere.
     */
    private function expenseAccountFor(?string $productId, string $fallback): string
    {
        if ($productId === null) {
            return $fallback;
        }

        $product = Product::query()->find($productId);

        if ($product === null || $product->expense_account_id === null) {
            return $fallback;
        }

        return $product->expense_account_id;
    }

    private function guard(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrderStatus::Billed) {
            throw PurchaseOrderRuleViolation::alreadyBilled(
                $order,
                $order->invoice()->value('reference'),
            );
        }

        if (! $order->isApproved()) {
            throw PurchaseOrderRuleViolation::notApproved($order);
        }

        $contact = $order->contact;

        if ($contact === null || $contact->status !== ContactStatus::Active) {
            throw PurchaseOrderRuleViolation::inactiveSupplier(
                $contact ?? $order->contact()->withTrashed()->firstOrFail(),
            );
        }

        $this->guardTaxesResolve($order);
    }

    /**
     * Every ordered tax must still resolve — taxes soft-delete, and the
     * recalculator's fallback for a missing tax is a silently zero-rated
     * bill.
     */
    private function guardTaxesResolve(PurchaseOrder $order): void
    {
        $ids = $order->items()->pluck('tax_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $alive = Tax::query()->whereKey($ids->all())->pluck('id');
        $missing = $ids->diff($alive);

        if ($missing->isEmpty()) {
            return;
        }

        $trashed = Tax::query()->withTrashed()->find($missing->first());
        $name = $trashed === null ? (string) $missing->first() : $trashed->name;

        throw PurchaseOrderRuleViolation::taxNoLongerAvailable($name);
    }
}
