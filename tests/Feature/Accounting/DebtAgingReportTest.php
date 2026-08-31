<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\ContactType;
use App\Enums\PurchaseInvoiceKind;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Reports\DebtAging;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The unified day-bucket debt aging report.
 *
 * The boundary tests are the point: a document exactly thirty days past due
 * is a coin flip between two buckets unless the rule is pinned, and totals
 * balance either way so nothing else would notice. And the null-due-date
 * fallback: a simple bill with no due date must age from its issue date, not
 * sit in "current" forever.
 */
final class DebtAgingReportTest extends TestCase
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

    /** Day 0 → current; day 30 → first bucket; day 31 → second; day 107 → last. */
    #[Test]
    public function the_bucket_boundaries_are_exact(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        $this->invoice('100.00', issue: '2026-05-01', due: '2026-07-05');   // -5 → current
        $this->invoice('200.00', issue: '2026-05-01', due: '2026-06-30');   //  0 → current
        $this->invoice('300.00', issue: '2026-05-01', due: '2026-05-31');   // 30 → 1–30
        $this->invoice('400.00', issue: '2026-05-01', due: '2026-05-30');   // 31 → 31–60
        $this->invoice('500.00', issue: '2026-03-01', due: '2026-04-01');   // 90 → 61–90
        $this->invoice('600.00', issue: '2026-03-01', due: '2026-03-15');   // 107 → 90+

        $data = app(DebtAging::class)->build($asOf);

        $this->assertSame('300.0000', $data->totals['current']);
        $this->assertSame('300.0000', $data->totals['b1_30']);
        $this->assertSame('400.0000', $data->totals['b31_60']);
        $this->assertSame('500.0000', $data->totals['b61_90']);
        $this->assertSame('600.0000', $data->totals['over_90']);
        $this->assertSame('2100.0000', $data->totals['total']);
    }

    #[Test]
    public function a_simple_bill_without_a_due_date_ages_from_its_issue_date(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        // Issued February 1, no due date: 149 days old — the naive NULL
        // arithmetic would file it under "current" forever.
        $bill = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextSimpleReference(),
            'kind' => PurchaseInvoiceKind::Simple,
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => CarbonImmutable::parse('2026-02-01'),
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $bill->getKey(),
            'product_description' => 'إيجار',
            'expense_account_id' => app(AccountRegistry::class)->get(SystemAccount::CostOfGoodsSold)->getKey(),
            'quantity' => '1',
            'unit_price' => '700.00',
            'is_inclusive' => true,
        ]);

        app(PurchaseInvoicePoster::class)->approve(
            app(PurchaseInvoiceRecalculator::class)->recalculate($bill->refresh()),
        );

        $data = app(DebtAging::class)->build($asOf, contactType: 'vendor');

        $this->assertSame('700.0000', $data->totals['over_90']);
        $this->assertSame('0.0000', $data->totals['current']);
    }

    #[Test]
    public function the_type_and_contact_filters_narrow_the_report(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        $this->invoice('100.00', issue: '2026-06-01', due: '2026-06-15');
        $this->bill('900.00', issue: '2026-06-01', due: '2026-06-15');

        $service = app(DebtAging::class);

        $this->assertSame('1000.0000', $service->build($asOf)->totals['total']);
        $this->assertSame('100.0000', $service->build($asOf, contactType: 'customer')->totals['total']);
        $this->assertSame('900.0000', $service->build($asOf, contactType: 'vendor')->totals['total']);
        $this->assertSame(
            '100.0000',
            $service->build($asOf, contactId: $this->customer->getKey())->totals['total'],
        );
    }

    #[Test]
    public function the_minimum_amount_filter_drops_small_rows(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        $this->invoice('100.00', issue: '2026-06-01', due: '2026-06-15');
        $this->bill('900.00', issue: '2026-06-01', due: '2026-06-15');

        $data = app(DebtAging::class)->build($asOf, minAmount: '200');

        $this->assertCount(1, $data->summary);
        $this->assertSame('900.0000', $data->totals['total']);
    }

    #[Test]
    public function the_details_view_shows_signed_delays_per_document(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        $this->invoice('100.00', issue: '2026-05-01', due: '2026-06-20');  // +10 overdue
        $this->invoice('200.00', issue: '2026-06-01', due: '2026-07-10');  // -10 not yet due

        $data = app(DebtAging::class)->build($asOf, view: 'details');

        $this->assertCount(2, $data->details);
        // Sorted most-overdue first.
        $this->assertSame(10, $data->details[0]->delayDays);
        $this->assertSame('100.0000', $data->details[0]->remainder);
        $this->assertSame(-10, $data->details[1]->delayDays);
        $this->assertSame('invoice', $data->details[0]->documentType);
    }

    #[Test]
    public function another_companys_debts_are_invisible(): void
    {
        $this->invoice('100.00', issue: '2026-06-01', due: '2026-06-15');

        $this->makeAccountingCompany(2026);

        $data = app(DebtAging::class)->build(CarbonImmutable::parse('2026-06-30'));

        $this->assertSame([], $data->summary);
    }

    // ------------------------------------------------------------------ helpers

    private function invoice(string $grossTotal, string $issue, string $due): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => CarbonImmutable::parse($issue),
            'due_date' => CarbonImmutable::parse($due),
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

    private function bill(string $grossTotal, string $issue, string $due): PurchaseInvoice
    {
        $bill = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => CarbonImmutable::parse($issue),
            'due_date' => CarbonImmutable::parse($due),
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
}
