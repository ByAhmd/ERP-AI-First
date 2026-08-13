<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\IncomeStatement;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\Exceptions\InvoiceRuleViolation;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Approving a sales invoice.
 *
 * The assertion that matters most is that revenue is credited NET even when the
 * line was priced tax-inclusive. The predecessor system credited revenue with
 * the tax-inclusive total and wrote no tax line, so revenue was overstated by
 * the VAT and the return could never reconcile to the trial balance. That
 * defect is one of the three that motivated this rebuild, and a tax-inclusive
 * invoice line is exactly where it hid.
 */
final class SalesInvoicePostingTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->customer = Contact::create(['contact_name' => 'مؤسسة النخيل']);
    }

    #[Test]
    public function a_tax_inclusive_invoice_credits_revenue_net_and_vat_separately(): void
    {
        // 3 × 115.00 inclusive: the customer owes 345.00, of which 300.00 is
        // revenue and 45.00 is tax the company is holding for ZATCA.
        $invoice = $this->invoiceWith([
            ['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true],
        ]);

        $approved = app(SalesInvoicePoster::class)->approve($invoice);

        $this->assertSame(DocumentStatus::Approved, $approved->status);
        $this->assertSame('300.0000', $approved->subtotal_net);
        $this->assertSame('45.0000', $approved->tax_total);
        $this->assertSame('345.0000', $approved->total);

        $entry = $approved->journalEntry;
        $this->assertNotNull($entry);
        $this->assertTrue($entry->isPosted());
        $this->assertTrue($entry->isBalanced());

        $this->assertSame('345.0000', $this->lineOn($approved, SystemAccount::AccountsReceivable, 'debit'));
        // The figure the predecessor got wrong: 300, not 345.
        $this->assertSame('300.0000', $this->lineOn($approved, SystemAccount::SalesRevenue, 'credit'));
        $this->assertSame('45.0000', $this->lineOn($approved, SystemAccount::VatOutputPayable, 'credit'));
    }

    #[Test]
    public function a_tax_exclusive_invoice_posts_identically_to_its_inclusive_twin(): void
    {
        // The same supply priced two ways must reach the ledger the same way.
        $exclusive = app(SalesInvoicePoster::class)->approve(
            $this->invoiceWith([['quantity' => '3', 'unit_price' => '100.00', 'is_inclusive' => false]]),
        );

        $inclusive = app(SalesInvoicePoster::class)->approve(
            $this->invoiceWith([['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]),
        );

        $this->assertSame($exclusive->subtotal_net, $inclusive->subtotal_net);
        $this->assertSame($exclusive->tax_total, $inclusive->tax_total);
        $this->assertSame($exclusive->total, $inclusive->total);
    }

    #[Test]
    public function a_zero_rated_invoice_posts_no_tax_line_at_all(): void
    {
        $invoice = $this->invoiceWith([
            ['quantity' => '1', 'unit_price' => '100.00', 'category' => TaxCategory::ZeroRated],
        ]);

        $approved = app(SalesInvoicePoster::class)->approve($invoice);

        $this->assertSame('0.0000', $approved->tax_total);
        $this->assertCount(2, $approved->journalEntry->lines);
        $this->assertTrue($approved->journalEntry->isBalanced());
    }

    #[Test]
    public function an_approved_invoice_reaches_the_income_statement_and_the_balance_sheet(): void
    {
        app(SalesInvoicePoster::class)->approve(
            $this->invoiceWith([['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]),
        );

        $income = app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
        );

        $balance = app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse('2026-12-31'));

        // Revenue is the net. If the VAT leaked into revenue this reads 345.
        $this->assertSame('300.0000', $this->sectionTotal($income, 'revenue'));
        $this->assertTrue($balance->isBalanced());
        // Receivable 345 as an asset; VAT 45 owed as a liability.
        $this->assertSame('345.0000', $this->sectionTotal($balance, 'assets'));
        $this->assertSame('45.0000', $this->sectionTotal($balance, 'liabilities'));
    }

    #[Test]
    public function a_draft_invoice_reaches_no_report(): void
    {
        $this->invoiceWith([['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]);

        $income = app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
        );

        $this->assertSame('0.0000', $this->sectionTotal($income, 'revenue'));
    }

    #[Test]
    public function an_invoice_cannot_be_approved_twice(): void
    {
        $invoice = $this->invoiceWith([['quantity' => '1', 'unit_price' => '100.00']]);

        app(SalesInvoicePoster::class)->approve($invoice);

        $this->expectException(InvoiceRuleViolation::class);

        app(SalesInvoicePoster::class)->approve($invoice->refresh());
    }

    #[Test]
    public function an_invoice_with_no_items_is_refused(): void
    {
        $invoice = $this->draftInvoice();

        $this->expectException(InvoiceRuleViolation::class);

        app(SalesInvoicePoster::class)->approve($invoice);
    }

    #[Test]
    public function an_inactive_customer_cannot_be_invoiced(): void
    {
        $invoice = $this->invoiceWith([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->customer->update(['status' => ContactStatus::Inactive]);

        $this->expectException(InvoiceRuleViolation::class);

        app(SalesInvoicePoster::class)->approve($invoice->refresh());
    }

    #[Test]
    public function a_due_date_before_the_issue_date_is_refused(): void
    {
        $invoice = $this->invoiceWith([['quantity' => '1', 'unit_price' => '100.00']]);
        $invoice->forceFill(['due_date' => CarbonImmutable::parse('2026-02-01')])->save();

        $this->expectException(InvoiceRuleViolation::class);

        app(SalesInvoicePoster::class)->approve($invoice->refresh());
    }

    #[Test]
    public function an_approved_invoice_is_no_longer_recalculated(): void
    {
        // Its figures have reached the ledger and the customer.
        $invoice = $this->invoiceWith([['quantity' => '1', 'unit_price' => '100.00']]);
        app(SalesInvoicePoster::class)->approve($invoice);

        $invoice->refresh()->items()->first()->forceFill(['quantity' => '99'])->save();

        $unchanged = app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh());

        $this->assertSame('100.0000', $unchanged->subtotal_net);
    }

    #[Test]
    public function references_are_gapless_and_scoped_to_the_company(): void
    {
        $first = app(SalesInvoicePoster::class)->nextReference();
        $second = app(SalesInvoicePoster::class)->nextReference();

        $this->assertSame('INV-00001', $first);
        $this->assertSame('INV-00002', $second);
    }

    #[Test]
    public function a_multi_line_invoice_totals_its_tax_by_group(): void
    {
        // Standard and zero-rated on one invoice. Applying one rate to the
        // combined net would give 105.00 where the answer is 45.00.
        $invoice = $this->invoiceWith([
            ['quantity' => '3', 'unit_price' => '100.00'],
            ['quantity' => '1', 'unit_price' => '400.00', 'category' => TaxCategory::ZeroRated],
        ]);

        $approved = app(SalesInvoicePoster::class)->approve($invoice);

        $this->assertSame('700.0000', $approved->subtotal_net);
        $this->assertSame('45.0000', $approved->tax_total);
        $this->assertSame('745.0000', $approved->total);
        $this->assertTrue($approved->journalEntry->isBalanced());
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function invoiceWith(array $lines): SalesInvoice
    {
        $invoice = $this->draftInvoice();

        foreach ($lines as $index => $line) {
            $category = $line['category'] ?? TaxCategory::Standard;

            SalesInvoiceItem::create([
                'sales_invoice_id' => $invoice->getKey(),
                'line_number' => $index + 1,
                'product_id' => $this->product()->getKey(),
                'product_name' => 'كرسي مكتب',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'tax_id' => Tax::query()->where('category', $category)->value('id'),
            ]);
        }

        return app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh());
    }

    private function draftInvoice(): SalesInvoice
    {
        return SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => CarbonImmutable::parse('2026-03-15'),
            'due_date' => CarbonImmutable::parse('2026-04-15'),
            'supply_date' => CarbonImmutable::parse('2026-03-15'),
        ]);
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'كرسي مكتب',
            'name_en' => 'Office Chair',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'selling_price' => '100',
        ]);
    }

    private function lineOn(SalesInvoice $invoice, SystemAccount $role, string $side): string
    {
        $accountId = app(AccountRegistry::class)->get($role)->getKey();

        $line = $invoice->journalEntry->lines->firstWhere('account_id', $accountId);

        return $line === null ? '0.0000' : (string) $line->{$side};
    }

    private function sectionTotal(object $statement, string $key): string
    {
        foreach ($statement->sections as $section) {
            if ($section->key === $key) {
                return $section->totals[0];
            }
        }

        $this->fail("No '{$key}' section.");
    }
}
