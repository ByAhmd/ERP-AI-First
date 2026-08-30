<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\Exceptions\PurchaseOrderRuleViolation;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseOrderApprover;
use App\Services\Purchases\PurchaseOrderConverter;
use App\Services\Purchases\PurchaseOrderRecalculator;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The purchase order lifecycle.
 *
 * The quotation's invariants on the buy side: nothing posts at any status,
 * one order converts to exactly one bill, the agreed prices carry verbatim
 * while the fiscal facts re-resolve, and overdue is a fact about the clock,
 * never a stored status.
 */
final class PurchaseOrderLifecycleTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->supplier = Contact::create([
            'contact_name' => 'شركة التوريدات الأولى',
            'type' => ContactType::Supplier,
        ]);
    }

    /** The invariant the absent journal_entry_id column enforces. */
    #[Test]
    public function nothing_posts_until_the_converted_bill_is_approved(): void
    {
        $order = $this->draftOrder([['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]);

        $before = JournalEntry::query()->count();

        $approved = app(PurchaseOrderApprover::class)->approve($order);

        $this->assertSame(PurchaseOrderStatus::Approved, $approved->status);
        $this->assertSame($before, JournalEntry::query()->count());

        $invoice = app(PurchaseOrderConverter::class)->convert($approved);

        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($before, JournalEntry::query()->count());

        app(PurchaseInvoicePoster::class)->approve($invoice);

        $this->assertSame($before + 1, JournalEntry::query()->count());
        $this->assertSame(PurchaseOrderStatus::Billed, $approved->refresh()->status);
    }

    #[Test]
    public function orders_number_from_their_own_series(): void
    {
        $this->assertSame('ORD-00001', app(PurchaseOrderApprover::class)->nextReference());
        $this->assertSame('BIL-00001', app(PurchaseInvoicePoster::class)->nextReference());
    }

    #[Test]
    public function conversion_carries_the_agreed_price_and_rebills_tax_at_todays_rate(): void
    {
        $order = $this->approvedOrder([['quantity' => '2', 'unit_price' => '100.00']]);

        $tax = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();
        $tax->forceFill(['rate' => '5.00'])->save();

        $invoice = app(PurchaseOrderConverter::class)->convert($order);
        $line = $invoice->items()->firstOrFail();

        // The agreement carries verbatim...
        $this->assertSame('100.0000', (string) $line->unit_price);
        // ...the fiscal facts are the bill's own.
        $this->assertSame('5.0000', (string) $line->tax_rate);
        $this->assertSame('10.0000', (string) $invoice->tax_total);

        // The order's own snapshot is untouched.
        $this->assertSame('15.0000', (string) $order->items()->firstOrFail()->tax_rate);
    }

    #[Test]
    public function conversion_resolves_the_expense_account_from_the_product(): void
    {
        $rent = Account::query()->where('is_postable', true)
            ->where('code', 'like', '52%')->firstOrFail();

        $this->product()->forceFill(['expense_account_id' => $rent->getKey()])->save();

        $order = $this->approvedOrder([['quantity' => '1', 'unit_price' => '100.00']]);

        $invoice = app(PurchaseOrderConverter::class)->convert($order);

        // The account is a property of the bill, resolved at conversion from
        // the product's current pointer.
        $this->assertSame($rent->getKey(), $invoice->items()->firstOrFail()->expense_account_id);
    }

    #[Test]
    public function an_order_converts_exactly_once(): void
    {
        $order = $this->approvedOrder([['quantity' => '1', 'unit_price' => '100.00']]);

        $invoice = app(PurchaseOrderConverter::class)->convert($order);

        try {
            app(PurchaseOrderConverter::class)->convert($order->refresh());
            $this->fail('A second conversion should refuse.');
        } catch (PurchaseOrderRuleViolation $refusal) {
            $this->assertStringContainsString($invoice->reference, $refusal->getMessage());
        }
    }

    #[Test]
    public function the_unique_index_refuses_a_second_bill_for_the_same_order(): void
    {
        $order = $this->approvedOrder([['quantity' => '1', 'unit_price' => '100.00']]);

        app(PurchaseOrderConverter::class)->convert($order);

        $this->expectException(QueryException::class);

        PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'purchase_order_id' => $order->getKey(),
            'issue_date' => today(),
            'due_date' => today(),
        ]);
    }

    #[Test]
    public function deleting_the_still_draft_converted_bill_releases_the_order(): void
    {
        $order = $this->approvedOrder([['quantity' => '1', 'unit_price' => '100.00']]);

        $invoice = app(PurchaseOrderConverter::class)->convert($order);

        $this->assertSame(PurchaseOrderStatus::Billed, $order->refresh()->status);

        $invoice->delete();

        $this->assertSame(PurchaseOrderStatus::Approved, $order->refresh()->status);

        $second = app(PurchaseOrderConverter::class)->convert($order->refresh());

        $this->assertSame($order->getKey(), $second->purchase_order_id);
    }

    #[Test]
    public function only_an_approved_order_converts(): void
    {
        $draft = $this->draftOrder([['quantity' => '1', 'unit_price' => '100.00']]);

        try {
            app(PurchaseOrderConverter::class)->convert($draft);
            $this->fail('A draft should not convert.');
        } catch (PurchaseOrderRuleViolation) {
            $this->assertSame(PurchaseOrderStatus::Draft, $draft->refresh()->status);
        }

        $cancelled = app(PurchaseOrderApprover::class)->cancel(
            $this->draftOrder([['quantity' => '1', 'unit_price' => '100.00']]),
        );

        $this->expectException(PurchaseOrderRuleViolation::class);

        app(PurchaseOrderConverter::class)->convert($cancelled);
    }

    #[Test]
    public function an_overdue_order_still_converts(): void
    {
        $order = $this->approvedOrder(
            [['quantity' => '1', 'unit_price' => '100.00']],
            issueDate: today()->subDays(40),
            expiryDate: today()->subDays(10),
        );

        $this->assertTrue($order->isOverdue());

        $invoice = app(PurchaseOrderConverter::class)->convert($order);

        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertTrue($invoice->issue_date->isToday());
        // Billed resolves the order — it is no longer overdue.
        $this->assertFalse($order->refresh()->isOverdue());
    }

    #[Test]
    public function a_deleted_tax_refuses_conversion_rather_than_zero_rating(): void
    {
        $bespoke = Tax::create([
            'name' => 'ضريبة مؤقتة',
            'category' => TaxCategory::Standard,
            'rate' => '15.00',
            'account_id' => Tax::query()->where('category', TaxCategory::Standard)->firstOrFail()->account_id,
        ]);

        $order = $this->draftOrder([['quantity' => '1', 'unit_price' => '100.00']]);
        $order->items()->update(['tax_id' => $bespoke->getKey()]);
        $order = app(PurchaseOrderApprover::class)->approve(
            app(PurchaseOrderRecalculator::class)->recalculate($order->refresh()),
        );

        $bespoke->delete();

        try {
            app(PurchaseOrderConverter::class)->convert($order);
            $this->fail('An order whose tax is gone should refuse to convert.');
        } catch (PurchaseOrderRuleViolation $refusal) {
            $this->assertStringContainsString('لم تعد متاحة', $refusal->getMessage());
            $this->assertSame(0, PurchaseInvoice::query()->count());
        }
    }

    #[Test]
    public function a_billed_order_cannot_be_cancelled(): void
    {
        $order = $this->approvedOrder([['quantity' => '1', 'unit_price' => '100.00']]);

        app(PurchaseOrderConverter::class)->convert($order);

        $this->expectException(PurchaseOrderRuleViolation::class);

        app(PurchaseOrderApprover::class)->cancel($order->refresh());
    }

    #[Test]
    public function an_inactive_supplier_refuses_at_conversion_not_two_screens_later(): void
    {
        $order = $this->approvedOrder([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->supplier->forceFill(['status' => ContactStatus::Inactive])->save();

        try {
            app(PurchaseOrderConverter::class)->convert($order);
            $this->fail('An inactive supplier should refuse at conversion.');
        } catch (PurchaseOrderRuleViolation) {
            $this->assertSame(0, PurchaseInvoice::query()->count());
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedOrder(
        array $lines,
        ?CarbonInterface $issueDate = null,
        ?CarbonInterface $expiryDate = null,
    ): PurchaseOrder {
        return app(PurchaseOrderApprover::class)->approve(
            $this->draftOrder($lines, $issueDate, $expiryDate),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftOrder(
        array $lines,
        ?CarbonInterface $issueDate = null,
        ?CarbonInterface $expiryDate = null,
    ): PurchaseOrder {
        $order = PurchaseOrder::create([
            'reference' => app(PurchaseOrderApprover::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => $issueDate ?? today(),
            'expiry_date' => $expiryDate ?? today()->addDays(30),
        ]);

        foreach ($lines as $line) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $order->getKey(),
                'product_id' => $this->product()->getKey(),
                'product_name' => 'ورق تصوير',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'tax_id' => Tax::query()->where('category', $line['category'] ?? TaxCategory::Standard)->value('id'),
            ]);
        }

        return app(PurchaseOrderRecalculator::class)->recalculate($order->refresh());
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'buying_price' => '100',
            'expense_account_id' => app(AccountRegistry::class)->get(SystemAccount::CostOfGoodsSold)->getKey(),
        ]);
    }
}
