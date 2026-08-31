<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ContactType;
use App\Enums\CreditNoteReason;
use App\Enums\StockAdjustmentKind;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\ProductUnitType;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseDebitNoteItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockMovement;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Reports\TrialBalance;
use App\Services\Inventory\Exceptions\StockRuleViolation;
use App\Services\Inventory\StockAdjustmentPoster;
use App\Services\Purchases\DebitNotePoster;
use App\Services\Purchases\DebitNoteRecalculator;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\CreditNotePoster;
use App\Services\Sales\CreditNoteRecalculator;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Stock-aware posting through the real documents.
 *
 * The invariant the whole slice defends, held by the closing test: after a
 * mixed sequence of bills, invoices, notes and adjustments, the inventory
 * control account's balance equals the stock subledger's total value —
 * exactly, to the fourth decimal.
 */
final class StockPostingTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private Contact $customer;

    private Contact $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->branch = Branch::query()->where('is_default', true)->firstOrFail();
        $this->customer = Contact::create(['contact_name' => 'مؤسسة النخيل']);
        $this->supplier = Contact::create([
            'contact_name' => 'شركة التوريدات الأولى',
            'type' => ContactType::Supplier,
        ]);

        $this->product = Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'is_sold' => true,
            'selling_price' => '30',
            'buying_price' => '10',
            'track_inventory' => true,
        ]);
    }

    /** The mixed bill: stocked lines reach 1140, untracked lines their own account. */
    #[Test]
    public function a_mixed_bill_debits_inventory_for_stocked_lines_only(): void
    {
        $rent = Account::query()->where('is_postable', true)
            ->where('code', 'like', '53%')->firstOrFail();

        $bill = $this->approvedBill([
            ['product' => $this->product, 'quantity' => '10', 'unit_price' => '10.00'],
            ['expense_account_id' => $rent->getKey(), 'quantity' => '1', 'unit_price' => '500.00'],
        ]);

        $inventory = $this->account(SystemAccount::Inventory);

        // 100 of goods into 1140; 500 of rent untouched by stock.
        $this->assertSame('100.0000', $this->lineOn($bill->journalEntry, $inventory->getKey(), 'debit'));
        $this->assertSame('500.0000', $this->lineOn($bill->journalEntry, $rent->getKey(), 'debit'));

        $cost = $this->cost();
        $this->assertSame('10.0000', (string) $cost->quantity_on_hand);
        $this->assertSame('100.0000', (string) $cost->total_value);
        $this->assertSame('10.0000', (string) $cost->average_cost);
    }

    /** COGS resolves at APPROVAL, not at draft time. */
    #[Test]
    public function an_invoice_posts_cogs_at_the_average_of_its_approval_moment(): void
    {
        $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '10.00']]);

        // Draft the invoice while avg = 10...
        $invoice = $this->draftInvoice([['product' => $this->product, 'quantity' => '5', 'unit_price' => '30.00']]);

        // ...then a second receipt moves the average to 15...
        $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '20.00']]);

        // ...and approval relieves at 15, not 10.
        $approved = app(SalesInvoicePoster::class)->approve($invoice);

        $cogs = $this->account(SystemAccount::CostOfGoodsSold);
        $inventory = $this->account(SystemAccount::Inventory);

        $this->assertSame('75.0000', $this->lineOn($approved->journalEntry, $cogs->getKey(), 'debit'));
        $this->assertSame('75.0000', $this->lineOn($approved->journalEntry, $inventory->getKey(), 'credit'));

        // One entry carries revenue and cost together — AR, revenue, COGS,
        // inventory (zero-rated, so no VAT line) — every line branched.
        $this->assertSame(4, $approved->journalEntry->lines()->count());
        $this->assertSame(
            4,
            $approved->journalEntry->lines()->where('branch_id', $this->branch->getKey())->count(),
        );
    }

    #[Test]
    public function an_invoice_beyond_the_branch_stock_is_refused_and_stays_draft(): void
    {
        $this->approvedBill([['product' => $this->product, 'quantity' => '3', 'unit_price' => '10.00']]);

        $invoice = $this->draftInvoice([['product' => $this->product, 'quantity' => '5', 'unit_price' => '30.00']]);

        try {
            app(SalesInvoicePoster::class)->approve($invoice);
            $this->fail('Selling more than the shelf holds should refuse.');
        } catch (StockRuleViolation $refusal) {
            $this->assertStringContainsString('غير متوفرة', $refusal->getMessage());
        }

        $invoice->refresh();
        $this->assertTrue($invoice->isDraft());
        $this->assertNull($invoice->journal_entry_id);
        // Nothing moved: quantity intact, no orphan movement rows.
        $this->assertSame('3.0000', (string) $this->cost()->quantity_on_hand);
    }

    /** Goods returns restock at current average; price adjustments move nothing. */
    #[Test]
    public function credit_notes_move_stock_only_for_goods_returns(): void
    {
        $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '10.00']]);
        $invoice = app(SalesInvoicePoster::class)->approve(
            $this->draftInvoice([['product' => $this->product, 'quantity' => '6', 'unit_price' => '30.00']]),
        );

        // A price adjustment naming quantities moves nothing.
        $this->approvedCreditNote($invoice, CreditNoteReason::PriceAdjustment, '2', '5.00');
        $this->assertSame('4.0000', (string) $this->cost()->quantity_on_hand);

        // A goods return brings quantity back at the current average and
        // posts the reversal pair.
        $note = $this->approvedCreditNote($invoice, CreditNoteReason::GoodsReturn, '4', '30.00');

        $this->assertSame('8.0000', (string) $this->cost()->quantity_on_hand);

        $inventory = $this->account(SystemAccount::Inventory);
        $cogs = $this->account(SystemAccount::CostOfGoodsSold);

        $this->assertSame('40.0000', $this->lineOn($note->journalEntry, $inventory->getKey(), 'debit'));
        $this->assertSame('40.0000', $this->lineOn($note->journalEntry, $cogs->getKey(), 'credit'));
    }

    /** The debit note credits 1140 at RELIEF; the net-vs-relief difference lands on 5150. */
    #[Test]
    public function a_goods_return_debit_note_relieves_at_average_with_the_difference_on_adjustments(): void
    {
        // Two receipts: avg lands at 15.
        $bill = $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '10.00']]);
        $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '20.00']]);

        // Return 5 units against the 10.00 bill: note net = 50, relief = 75.
        $note = $this->approvedDebitNote($bill, returnsGoods: true, quantity: '5', unitPrice: '10.00');

        $inventory = $this->account(SystemAccount::Inventory);
        $adjustments = $this->account(SystemAccount::InventoryAdjustment);

        $this->assertSame('75.0000', $this->lineOn($note->journalEntry, $inventory->getKey(), 'credit'));
        // Relief exceeded the note's net by 25 — debited to تسويات المخزون.
        $this->assertSame('25.0000', $this->lineOn($note->journalEntry, $adjustments->getKey(), 'debit'));

        $this->assertSame('15.0000', (string) $this->cost()->quantity_on_hand);
    }

    #[Test]
    public function a_rate_correction_debit_note_moves_no_stock(): void
    {
        $bill = $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '10.00']]);

        $note = $this->approvedDebitNote($bill, returnsGoods: false, quantity: '5', unitPrice: '4.00');

        $this->assertTrue($note->isApproved());
        $this->assertSame('10.0000', (string) $this->cost()->quantity_on_hand);
    }

    #[Test]
    public function an_opening_adjustment_seeds_quantity_and_average(): void
    {
        $adjustment = $this->approvedAdjustment(StockAdjustmentKind::Opening, '20', '7.50');

        $cost = $this->cost();
        $this->assertSame('20.0000', (string) $cost->quantity_on_hand);
        $this->assertSame('150.0000', (string) $cost->total_value);
        $this->assertSame('7.5000', (string) $cost->average_cost);

        $inventory = $this->account(SystemAccount::Inventory);
        $suspense = $this->account(SystemAccount::OpeningBalanceSuspense);

        $this->assertSame('150.0000', $this->lineOn($adjustment->journalEntry, $inventory->getKey(), 'debit'));
        $this->assertSame('150.0000', $this->lineOn($adjustment->journalEntry, $suspense->getKey(), 'credit'));
    }

    #[Test]
    public function a_count_decrease_relieves_at_the_running_average(): void
    {
        $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '12.00']]);

        $adjustment = $this->approvedAdjustment(StockAdjustmentKind::Count, '-3', null);

        $inventory = $this->account(SystemAccount::Inventory);
        $offset = $this->account(SystemAccount::InventoryAdjustment);

        $this->assertSame('36.0000', $this->lineOn($adjustment->journalEntry, $inventory->getKey(), 'credit'));
        $this->assertSame('36.0000', $this->lineOn($adjustment->journalEntry, $offset->getKey(), 'debit'));
        $this->assertSame('7.0000', (string) $this->cost()->quantity_on_hand);

        $item = $adjustment->items()->firstOrFail();
        $this->assertSame('12.0000', (string) $item->resolved_unit_cost);
    }

    /** The closing invariant: the control account equals the subledger, exactly. */
    #[Test]
    public function the_inventory_control_ties_to_the_stock_subledger(): void
    {
        $this->approvedAdjustment(StockAdjustmentKind::Opening, '10', '8.00');
        $bill = $this->approvedBill([['product' => $this->product, 'quantity' => '10', 'unit_price' => '12.00']]);

        $invoice = app(SalesInvoicePoster::class)->approve(
            $this->draftInvoice([['product' => $this->product, 'quantity' => '7', 'unit_price' => '30.00']]),
        );

        $this->approvedCreditNote($invoice, CreditNoteReason::GoodsReturn, '2', '30.00');
        $this->approvedDebitNote($bill, returnsGoods: true, quantity: '3', unitPrice: '12.00');
        $this->approvedAdjustment(StockAdjustmentKind::Count, '-1', null);

        $trial = app(TrialBalance::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
        );

        $row = $trial->firstWhere('code', '1140');
        $glBalance = bcsub((string) $row->closingDebit, (string) $row->closingCredit, 4);

        $subledger = (string) ProductCost::query()->sum('total_value');

        $this->assertSame($glBalance, bcadd($subledger, '0', 4));
        $this->assertTrue($trial->firstWhere('code', '1140') !== null);
    }

    /** Existing untracked products keep posting exactly as before the slice. */
    #[Test]
    public function untracked_products_post_as_they_always_did(): void
    {
        $untracked = Product::create([
            'name' => 'خدمة استشارية',
            'name_en' => 'Consulting',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'buying_price' => '100',
        ]);

        $bill = $this->approvedBill([['product' => $untracked, 'quantity' => '1', 'unit_price' => '100.00']]);

        $inventory = $this->account(SystemAccount::Inventory);

        $this->assertNull($this->lineOn($bill->journalEntry, $inventory->getKey(), 'debit'));
        $this->assertSame(0, StockMovement::query()->count());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedBill(array $lines): PurchaseInvoice
    {
        $bill = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'branch_id' => $this->branch->getKey(),
            'issue_date' => today()->subDays(10),
            'due_date' => today()->addDays(30),
        ]);

        foreach ($lines as $line) {
            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $bill->getKey(),
                'product_id' => isset($line['product']) ? $line['product']->getKey() : null,
                'product_description' => 'بند',
                'expense_account_id' => $line['expense_account_id']
                    ?? $this->account(SystemAccount::CostOfGoodsSold)->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_id' => Tax::query()->where('category', TaxCategory::ZeroRated)->value('id'),
            ]);
        }

        return app(PurchaseInvoicePoster::class)->approve(
            app(PurchaseInvoiceRecalculator::class)->recalculate($bill->refresh()),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftInvoice(array $lines): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'branch_id' => $this->branch->getKey(),
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
            'supply_date' => today(),
        ]);

        foreach ($lines as $line) {
            SalesInvoiceItem::create([
                'sales_invoice_id' => $invoice->getKey(),
                'product_id' => $line['product']->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_id' => Tax::query()->where('category', TaxCategory::ZeroRated)->value('id'),
            ]);
        }

        return app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh());
    }

    private function approvedCreditNote(
        SalesInvoice $invoice,
        CreditNoteReason $reason,
        string $quantity,
        string $unitPrice,
    ): SalesCreditNote {
        $note = SalesCreditNote::create([
            'reference' => app(CreditNotePoster::class)->nextReference(),
            'contact_id' => $invoice->contact_id,
            'parent_id' => $invoice->getKey(),
            'branch_id' => $this->branch->getKey(),
            'original_invoice_number' => $invoice->reference,
            'issue_date' => today(),
            'due_date' => today(),
            'event_date' => today(),
            'reason_code' => $reason,
            'reason_text' => 'تصحيح',
        ]);

        SalesCreditNoteItem::create([
            'sales_credit_note_id' => $note->getKey(),
            'product_id' => $this->product->getKey(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return app(CreditNotePoster::class)->approve(
            app(CreditNoteRecalculator::class)->recalculate($note->refresh()),
        );
    }

    private function approvedDebitNote(
        PurchaseInvoice $bill,
        bool $returnsGoods,
        string $quantity,
        string $unitPrice,
    ): PurchaseDebitNote {
        $note = PurchaseDebitNote::create([
            'reference' => app(DebitNotePoster::class)->nextReference(),
            'contact_id' => $bill->contact_id,
            'parent_id' => $bill->getKey(),
            'branch_id' => $this->branch->getKey(),
            'returns_goods' => $returnsGoods,
            'original_invoice_number' => 'placeholder',
            'issue_date' => today(),
        ]);

        PurchaseDebitNoteItem::create([
            'purchase_debit_note_id' => $note->getKey(),
            'purchase_invoice_item_id' => $bill->items()->firstOrFail()->getKey(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return app(DebitNotePoster::class)->approve(
            app(DebitNoteRecalculator::class)->recalculate($note->refresh()),
        );
    }

    private function approvedAdjustment(
        StockAdjustmentKind $kind,
        string $quantityChange,
        ?string $unitCost,
    ): StockAdjustment {
        $adjustment = StockAdjustment::create([
            'reference' => app(StockAdjustmentPoster::class)->nextReference(),
            'kind' => $kind,
            'branch_id' => $this->branch->getKey(),
            'adjustment_date' => today(),
        ]);

        StockAdjustmentItem::create([
            'stock_adjustment_id' => $adjustment->getKey(),
            'product_id' => $this->product->getKey(),
            'quantity_change' => $quantityChange,
            'unit_cost' => $unitCost,
        ]);

        return app(StockAdjustmentPoster::class)->approve($adjustment->refresh());
    }

    private function cost(): ProductCost
    {
        return ProductCost::query()
            ->where('product_id', $this->product->getKey())
            ->firstOrFail();
    }

    private function account(SystemAccount $role): Account
    {
        return app(AccountRegistry::class)->get($role);
    }

    private function lineOn(?JournalEntry $entry, string $accountId, string $side): ?string
    {
        $line = $entry?->lines()->where('account_id', $accountId)->first();

        if ($line === null) {
            return null;
        }

        $amount = (string) $line->{$side};

        return bccomp($amount, '0', 4) === 0 ? null : $amount;
    }
}
