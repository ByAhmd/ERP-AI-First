<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactStatus;
use App\Enums\ContactType;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\Exceptions\PurchaseInvoiceRuleViolation;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\TaxTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Purchase invoice posting.
 *
 * Every wrong version of this posting balances. Debiting the expense with
 * the gross balances; debiting VAT output instead of VAT input balances;
 * folding every line into one account balances. So these tests assert
 * ACCOUNT IDENTITY and SIDE — which account moved, and which way — never
 * balance alone.
 */
final class PurchaseInvoicePostingTest extends TestCase
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

    /** The founding assertion of the buy side: 1150 debited, 2120 untouched. */
    #[Test]
    public function approval_debits_expense_net_and_input_vat_and_credits_payables_total(): void
    {
        $invoice = $this->approvedBill([[
            'quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true,
        ]]);

        $this->assertSame(DocumentStatus::Approved, $invoice->status);
        $this->assertTrue($invoice->journalEntry->isBalanced());

        // Inclusive 345 gross → 300 net + 45 tax.
        $this->assertSame('300.0000', $this->lineOn($invoice, $this->cogs()->getKey(), 'debit'));
        $this->assertSame('45.0000', $this->lineOn($invoice, $this->account(SystemAccount::VatInputRecoverable)->getKey(), 'debit'));
        $this->assertSame('345.0000', $this->lineOn($invoice, $this->account(SystemAccount::AccountsPayable)->getKey(), 'credit'));

        // The half-flip trap: VAT OUTPUT must have no line at all.
        $this->assertNull($this->lineOn($invoice, $this->account(SystemAccount::VatOutputPayable)->getKey(), 'debit'));
        $this->assertNull($this->lineOn($invoice, $this->account(SystemAccount::VatOutputPayable)->getKey(), 'credit'));
    }

    #[Test]
    public function lines_on_different_expense_accounts_produce_separate_debits_summing_to_the_net(): void
    {
        $rent = Account::query()->where('is_postable', true)
            ->where('code', 'like', '52%')->firstOrFail();

        $invoice = $this->draftBill([
            ['quantity' => '1', 'unit_price' => '1000.00'],
            ['quantity' => '1', 'unit_price' => '500.00', 'expense_account_id' => $rent->getKey()],
        ]);

        $approved = app(PurchaseInvoicePoster::class)->approve($invoice);

        // Rent reaches rent, goods reach cost of goods sold — one folded
        // account here is how gross margin becomes fiction.
        $this->assertSame('1000.0000', $this->lineOn($approved, $this->cogs()->getKey(), 'debit'));
        $this->assertSame('500.0000', $this->lineOn($approved, $rent->getKey(), 'debit'));
        $this->assertSame('1500.0000', (string) $approved->subtotal_net);
    }

    #[Test]
    public function a_zero_rated_bill_approves_with_no_vat_line(): void
    {
        $invoice = $this->approvedBill([[
            'quantity' => '10', 'unit_price' => '50.00', 'category' => TaxCategory::ZeroRated,
        ]]);

        $this->assertSame('500.0000', $this->lineOn($invoice, $this->cogs()->getKey(), 'debit'));
        $this->assertNull($this->lineOn($invoice, $this->account(SystemAccount::VatInputRecoverable)->getKey(), 'debit'));
        $this->assertSame('500.0000', $this->lineOn($invoice, $this->account(SystemAccount::AccountsPayable)->getKey(), 'credit'));
    }

    #[Test]
    public function a_draft_touches_nothing(): void
    {
        $this->draftBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->assertSame(0, JournalEntry::query()->count());
    }

    #[Test]
    public function the_same_supplier_invoice_cannot_be_keyed_twice(): void
    {
        $this->approvedBill(
            [['quantity' => '1', 'unit_price' => '100.00']],
            supplierNumber: 'SUP-2026-001',
        );

        // The composite unique refuses at INSERT — a duplicate cannot even
        // be drafted, which is stronger than a guard at approval.
        $this->expectException(QueryException::class);

        $this->draftBill(
            [['quantity' => '1', 'unit_price' => '200.00']],
            supplierNumber: 'SUP-2026-001',
        );
    }

    #[Test]
    public function bills_number_from_their_own_series_and_leave_the_sales_counter_untouched(): void
    {
        $this->assertSame('BIL-00001', app(PurchaseInvoicePoster::class)->nextReference());
        // The ZATCA-gapless sales series must show no purchase-shaped hole.
        $this->assertSame('INV-00001', app(SalesInvoicePoster::class)->nextReference());
    }

    #[Test]
    public function a_bill_cannot_even_exist_without_a_supplier(): void
    {
        // The schema, not the guard, is the wall here: contact_id is NOT
        // NULL, so an anonymous input-VAT claim is unrepresentable. The
        // poster's missingSupplier refusal remains as defence in depth.
        $invoice = $this->draftBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->expectException(QueryException::class);

        PurchaseInvoice::query()->whereKey($invoice->getKey())
            ->toBase()->update(['contact_id' => null]);
    }

    #[Test]
    public function a_customer_typed_contact_is_refused(): void
    {
        $customer = Contact::create(['contact_name' => 'عميل', 'type' => ContactType::Customer]);

        $invoice = $this->draftBill([['quantity' => '1', 'unit_price' => '100.00']]);
        $invoice->forceFill(['contact_id' => $customer->getKey()])->save();

        try {
            app(PurchaseInvoicePoster::class)->approve($invoice->refresh());
            $this->fail('A bill against a customer should refuse.');
        } catch (PurchaseInvoiceRuleViolation) {
            $this->assertSame(0, JournalEntry::query()->count());
        }
    }

    #[Test]
    public function an_inactive_supplier_is_refused(): void
    {
        $invoice = $this->draftBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->supplier->forceFill(['status' => ContactStatus::Inactive])->save();

        $this->expectException(PurchaseInvoiceRuleViolation::class);

        app(PurchaseInvoicePoster::class)->approve($invoice);
    }

    #[Test]
    public function a_standard_bill_needs_a_due_date_no_earlier_than_issue(): void
    {
        $invoice = $this->draftBill([['quantity' => '1', 'unit_price' => '100.00']]);
        $invoice->forceFill(['due_date' => null])->save();

        try {
            app(PurchaseInvoicePoster::class)->approve($invoice->refresh());
            $this->fail('A standard bill without a due date should refuse.');
        } catch (PurchaseInvoiceRuleViolation) {
            $this->assertSame(0, JournalEntry::query()->count());
        }

        $invoice->forceFill([
            'due_date' => $invoice->issue_date->subDay(),
        ])->save();

        $this->expectException(PurchaseInvoiceRuleViolation::class);

        app(PurchaseInvoicePoster::class)->approve($invoice->refresh());
    }

    #[Test]
    public function a_non_postable_expense_account_is_refused(): void
    {
        // A header account: real, but postings belong on its children.
        $header = Account::query()->where('is_postable', false)->firstOrFail();

        $invoice = $this->draftBill([[
            'quantity' => '1', 'unit_price' => '100.00', 'expense_account_id' => $header->getKey(),
        ]]);

        $this->expectException(PurchaseInvoiceRuleViolation::class);

        app(PurchaseInvoicePoster::class)->approve($invoice);
    }

    #[Test]
    public function an_approved_bill_cannot_be_approved_again(): void
    {
        $invoice = $this->approvedBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->expectException(PurchaseInvoiceRuleViolation::class);

        app(PurchaseInvoicePoster::class)->approve($invoice);
    }

    #[Test]
    public function the_item_hook_numbers_lines_and_falls_back_to_the_products_expense_account(): void
    {
        $product = $this->product();
        $stationery = Account::query()->where('is_postable', true)
            ->where('code', 'like', '5%')->where('code', '!=', '5100')->firstOrFail();
        $product->forceFill(['expense_account_id' => $stationery->getKey()])->save();

        $invoice = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => today(),
            'due_date' => today(),
        ]);

        // No line_number, no expense account — the repeater's shape.
        $item = PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $invoice->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '1',
            'unit_price' => '100.00',
        ]);

        $this->assertSame(1, $item->line_number);
        $this->assertSame($stationery->getKey(), $item->expense_account_id);
        $this->assertSame($product->name, $item->product_name);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedBill(array $lines, ?string $supplierNumber = null): PurchaseInvoice
    {
        return app(PurchaseInvoicePoster::class)->approve(
            $this->draftBill($lines, $supplierNumber),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftBill(array $lines, ?string $supplierNumber = null): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'supplier_invoice_number' => $supplierNumber,
            'issue_date' => today(),
            'due_date' => today()->addDays(30),
        ]);

        foreach ($lines as $line) {
            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $invoice->getKey(),
                'product_id' => $this->product()->getKey(),
                'product_name' => 'ورق تصوير',
                'expense_account_id' => $line['expense_account_id'] ?? $this->cogs()->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'tax_id' => Tax::query()->where('category', $line['category'] ?? TaxCategory::Standard)->value('id'),
            ]);
        }

        return app(PurchaseInvoiceRecalculator::class)->recalculate($invoice->refresh());
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'buying_price' => '100',
        ]);
    }

    private function cogs(): Account
    {
        return $this->account(SystemAccount::CostOfGoodsSold);
    }

    private function account(SystemAccount $role): Account
    {
        return app(AccountRegistry::class)->get($role);
    }

    private function lineOn(PurchaseInvoice $invoice, string $accountId, string $side): ?string
    {
        $line = $invoice->journalEntry?->lines()
            ->where('account_id', $accountId)
            ->first();

        if ($line === null) {
            return null;
        }

        $amount = (string) $line->{$side};

        return bccomp($amount, '0', 4) === 0 ? null : $amount;
    }
}
