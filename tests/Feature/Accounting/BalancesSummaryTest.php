<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\ContactType;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SupplierPayment;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Reports\TrialBalance;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Purchases\SupplierPaymentPoster;
use App\Services\Reports\BalancesSummary;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\CustomerReceiptPoster;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The balances summary reports — ملخص مستحقات العملاء والموردين.
 *
 * The report where the unused vouchers finally surface. Its invariant: the
 * net total equals the control account less the advances account, because
 * the three columns are exactly those two balances pulled apart.
 */
final class BalancesSummaryTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Contact $customer;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->customer = Contact::create(['contact_name' => 'مؤسسة النخيل']);
        $this->supplier = Contact::create([
            'contact_name' => 'شركة التوريدات الأولى',
            'type' => ContactType::Supplier,
        ]);
    }

    #[Test]
    public function the_customer_summary_nets_invoices_against_unused_receipts(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        $this->invoice('1000.00', '2026-06-01');

        // An advance never applied: 400 sitting as a receipt.
        $receipt = CustomerReceipt::create([
            'reference' => app(CustomerReceiptPoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'deposit_account_id' => $this->paymentAccount()->getKey(),
            'receipt_date' => CarbonImmutable::parse('2026-06-10'),
            'amount' => '400.00',
        ]);
        app(CustomerReceiptPoster::class)->approve($receipt);

        $report = app(BalancesSummary::class)->customers($asOf);

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];

        $this->assertSame('1000.0000', $row->openInvoices);
        $this->assertSame('400.0000', $row->unusedVouchers);
        $this->assertSame('600.0000', $row->net);

        // The tie: net = AR control − customer advances control.
        $trial = app(TrialBalance::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: $asOf,
        );

        $ar = $trial->firstWhere('code', '1130');
        $advances = $trial->firstWhere('code', '2180');

        $expected = bcsub(
            bcsub((string) $ar->closingDebit, (string) $ar->closingCredit, 4),
            bcsub((string) $advances->closingCredit, (string) $advances->closingDebit, 4),
            4,
        );

        $this->assertSame($expected, $report['totals']['net']);
    }

    #[Test]
    public function the_supplier_summary_nets_bills_against_unused_payments(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        $this->bill('2000.00', '2026-06-01');

        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => CarbonImmutable::parse('2026-06-10'),
            'amount' => '500.00',
        ]);
        app(SupplierPaymentPoster::class)->approve($payment);

        $report = app(BalancesSummary::class)->suppliers($asOf);

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];

        $this->assertSame('2000.0000', $row->openInvoices);
        $this->assertSame('500.0000', $row->unusedVouchers);
        $this->assertSame('1500.0000', $row->net);
    }

    #[Test]
    public function a_contact_with_only_an_advance_still_gets_a_row(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        // No invoices at all — just money on account. The union over the
        // three maps is what puts this customer on the report.
        $receipt = CustomerReceipt::create([
            'reference' => app(CustomerReceiptPoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'deposit_account_id' => $this->paymentAccount()->getKey(),
            'receipt_date' => CarbonImmutable::parse('2026-06-10'),
            'amount' => '250.00',
        ]);
        app(CustomerReceiptPoster::class)->approve($receipt);

        $report = app(BalancesSummary::class)->customers($asOf);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('0.0000', $report['rows'][0]->openInvoices);
        $this->assertSame('250.0000', $report['rows'][0]->unusedVouchers);
        $this->assertSame('-250.0000', $report['rows'][0]->net);
    }

    // ------------------------------------------------------------------ helpers

    private function invoice(string $grossTotal, string $issue): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => CarbonImmutable::parse($issue),
            'due_date' => CarbonImmutable::parse($issue)->addDays(30),
            'supply_date' => CarbonImmutable::parse($issue),
        ]);

        SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->getKey(),
            'product_name' => 'خدمة',
            'quantity' => '1',
            'unit_price' => $grossTotal,
            'is_inclusive' => true,
            'tax_id' => Tax::query()->where('category', TaxCategory::ZeroRated)->value('id'),
        ]);

        return app(SalesInvoicePoster::class)->approve(
            app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh()),
        );
    }

    private function bill(string $grossTotal, string $issue): PurchaseInvoice
    {
        $bill = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => CarbonImmutable::parse($issue),
            'due_date' => CarbonImmutable::parse($issue)->addDays(30),
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $bill->getKey(),
            'product_description' => 'توريد',
            'expense_account_id' => app(AccountRegistry::class)->get(SystemAccount::CostOfGoodsSold)->getKey(),
            'quantity' => '1',
            'unit_price' => $grossTotal,
            'is_inclusive' => true,
            'tax_id' => Tax::query()->where('category', TaxCategory::ZeroRated)->value('id'),
        ]);

        return app(PurchaseInvoicePoster::class)->approve(
            app(PurchaseInvoiceRecalculator::class)->recalculate($bill->refresh()),
        );
    }

    private function paymentAccount(): Account
    {
        return Account::query()->where('is_payment_account', true)->orderBy('code')->firstOrFail();
    }
}
