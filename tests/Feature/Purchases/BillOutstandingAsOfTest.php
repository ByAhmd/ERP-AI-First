<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Enums\PurchaseInvoiceKind;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SupplierPayment;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\BillOutstanding;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Purchases\SupplierPaymentPoster;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The as-of date bound on bill outstanding — the payables mirror.
 *
 * The sales twin carries the full battery; this file pins the mirror-specific
 * facts: the same effective-date rule on payment allocations, and the simple
 * bill's presence in the grouped payables position.
 */
final class BillOutstandingAsOfTest extends TestCase
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

    #[Test]
    public function as_of_today_equals_the_undated_figure(): void
    {
        $bill = $this->approvedBill('1000.00', CarbonImmutable::parse('2026-03-01'));

        $service = app(BillOutstanding::class);

        $this->assertSame(
            $service->outstanding($bill),
            $service->outstanding($bill, CarbonImmutable::now()),
        );
    }

    #[Test]
    public function an_advance_applied_later_settles_at_the_allocation_date(): void
    {
        $bill = $this->approvedBill('1000.00', CarbonImmutable::parse('2026-05-20'));

        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => CarbonImmutable::parse('2026-06-01'),
            'amount' => '1000.00',
        ]);
        app(SupplierPaymentPoster::class)->approve($payment);

        app(SupplierPaymentPoster::class)->allocate(
            $payment,
            $bill,
            '1000.0000',
            CarbonImmutable::parse('2026-07-10'),
        );

        $service = app(BillOutstanding::class);
        $bill = $bill->refresh();

        $this->assertSame('1000.0000', $service->outstanding($bill, CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('0.0000', $service->outstanding($bill, CarbonImmutable::parse('2026-07-31')));

        $this->assertSame('1000.0000', $service->unallocatedAdvancesTotal(CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('0.0000', $service->unallocatedAdvancesTotal(CarbonImmutable::parse('2026-07-31')));
    }

    #[Test]
    public function simple_bills_stand_beside_standard_ones_in_the_grouped_position(): void
    {
        $this->approvedBill('1000.00', CarbonImmutable::parse('2026-06-01'));

        $simple = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextSimpleReference(),
            'kind' => PurchaseInvoiceKind::Simple,
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => CarbonImmutable::parse('2026-06-05'),
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $simple->getKey(),
            'product_description' => 'إيجار',
            'expense_account_id' => app(AccountRegistry::class)->get(SystemAccount::CostOfGoodsSold)->getKey(),
            'quantity' => '1',
            'unit_price' => '500.00',
            'is_inclusive' => true,
        ]);

        app(PurchaseInvoicePoster::class)->approve(
            app(PurchaseInvoiceRecalculator::class)->recalculate($simple->refresh()),
        );

        $open = app(BillOutstanding::class)->openByContact(CarbonImmutable::parse('2026-06-30'));
        $row = $open[$this->supplier->getKey()];

        // One table, no UNION: both kinds in one figure.
        $this->assertSame('1500.0000', $row['amount']);
        $this->assertSame(2, $row['count']);
    }

    // ------------------------------------------------------------------ helpers

    private function approvedBill(string $grossTotal, CarbonImmutable $issueDate): PurchaseInvoice
    {
        $bill = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => $issueDate,
            'due_date' => $issueDate->addDays(30),
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
