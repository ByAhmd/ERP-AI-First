<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\CustomerReceipt;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Services\Accounting\Reports\TrialBalance;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\CustomerReceiptPoster;
use App\Services\Sales\Reports\CustomerAging;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The customer receivables aging report.
 *
 * The one test that matters most here is the tie: the grid's primary column,
 * plus the standalone credit notes it deliberately leaves out, must equal the
 * receivable control account's closing balance at the same date — and the
 * advances line must equal the customer-advances control. A report that does
 * not reconcile to the trial balance is a second set of books.
 */
final class CustomerAgingReportTest extends TestCase
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
    public function a_snapshot_shows_open_remainders_with_their_counts(): void
    {
        $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));
        $paid = $this->approvedInvoice('600.00', CarbonImmutable::parse('2026-06-05'));
        $partial = $this->approvedInvoice('400.00', CarbonImmutable::parse('2026-06-10'));

        $this->receiptAllocated($paid, '600.0000', CarbonImmutable::parse('2026-06-15'));
        $this->receiptAllocated($partial, '150.0000', CarbonImmutable::parse('2026-06-20'));

        $report = app(CustomerAging::class)->build(CarbonImmutable::parse('2026-06-30'));

        $this->assertCount(1, $report->rows);
        $row = $report->rows[0];

        // 1000 open + 250 remainder; the fully-paid invoice is gone, the
        // partially-paid one counts once at its remainder.
        $this->assertSame('1250.0000', $row->cells[0]['amount']);
        $this->assertSame(2, $row->cells[0]['count']);
        $this->assertSame($row->cells[0], $report->totals[0]);
    }

    #[Test]
    public function comparison_columns_land_on_prior_month_ends_and_zero_fill(): void
    {
        $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));

        $report = app(CustomerAging::class)->build(
            CarbonImmutable::parse('2026-08-15'),
            unit: 'month',
            periods: 3,
        );

        $this->assertSame(
            ['2026-08-15', '2026-07-31', '2026-06-30', '2026-05-31'],
            array_map(fn ($d) => $d->toDateString(), $report->dates),
        );

        $row = $report->rows[0];

        // Open in every column since issue — and zero-filled before it,
        // never blank.
        $this->assertSame('1000.0000', $row->cells[0]['amount']);
        $this->assertSame('1000.0000', $row->cells[2]['amount']);
        $this->assertSame(['amount' => '0.0000', 'count' => 0], $row->cells[3]);
    }

    /** The drift guard: the report must reconcile to the trial balance. */
    #[Test]
    public function the_grid_plus_its_footer_ties_to_the_control_accounts(): void
    {
        $asOf = CarbonImmutable::parse('2026-06-30');

        // A mixed book: an open invoice, a partially paid one, and an
        // advance received but never applied.
        $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));
        $partial = $this->approvedInvoice('400.00', CarbonImmutable::parse('2026-06-05'));
        $this->receiptAllocated($partial, '150.0000', CarbonImmutable::parse('2026-06-10'));

        $advance = CustomerReceipt::create([
            'reference' => app(CustomerReceiptPoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'deposit_account_id' => $this->paymentAccount()->getKey(),
            'receipt_date' => CarbonImmutable::parse('2026-06-12'),
            'amount' => '500.00',
        ]);
        app(CustomerReceiptPoster::class)->approve($advance);

        $service = app(CustomerAging::class);
        $report = $service->build($asOf);
        $reconciliation = $service->reconciliation($asOf);

        $trial = app(TrialBalance::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: $asOf,
        );

        $ar = $trial->firstWhere('code', '1130');
        $advances = $trial->firstWhere('code', '2180');

        // Grid total − standalone notes = AR control. No standalone notes
        // here, so the tie is direct.
        $gridTotal = $report->totals[0]['amount'];
        $arClosing = bcsub((string) $ar->closingDebit, (string) $ar->closingCredit, 4);

        $this->assertSame(
            $arClosing,
            bcsub($gridTotal, $reconciliation['unapplied_notes'], 4),
        );

        // And the advances line equals the customer-advances liability.
        $advancesClosing = bcsub((string) $advances->closingCredit, (string) $advances->closingDebit, 4);

        $this->assertSame('500.0000', $reconciliation['advances']);
        $this->assertSame($advancesClosing, $reconciliation['advances']);
    }

    #[Test]
    public function foreign_currency_documents_are_counted_for_the_warning(): void
    {
        $invoice = $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));

        $this->assertSame(0, app(CustomerAging::class)->build(CarbonImmutable::parse('2026-06-30'))->foreignCount);

        $usd = Currency::query()->where('code', 'USD')->first()
            ?? Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2, 'is_active' => true]);

        SalesInvoice::query()->whereKey($invoice->getKey())
            ->toBase()->update(['currency_id' => $usd->getKey()]);

        $this->assertSame(1, app(CustomerAging::class)->build(CarbonImmutable::parse('2026-06-30'))->foreignCount);
    }

    #[Test]
    public function another_companys_book_is_invisible(): void
    {
        $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));

        $this->makeAccountingCompany(2026);

        $report = app(CustomerAging::class)->build(CarbonImmutable::parse('2026-06-30'));

        $this->assertSame([], $report->rows);
    }

    // ------------------------------------------------------------------ helpers

    private function approvedInvoice(string $grossTotal, CarbonImmutable $issueDate): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => $issueDate,
            'due_date' => $issueDate->addDays(30),
            'supply_date' => $issueDate,
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

    private function receiptAllocated(SalesInvoice $invoice, string $amount, CarbonImmutable $date): CustomerReceipt
    {
        $receipt = CustomerReceipt::create([
            'reference' => app(CustomerReceiptPoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'deposit_account_id' => $this->paymentAccount()->getKey(),
            'receipt_date' => $date,
            'amount' => $amount,
        ]);

        $receipt->allocations()->create([
            'sales_invoice_id' => $invoice->getKey(),
            'amount' => $amount,
        ]);

        return app(CustomerReceiptPoster::class)->approve($receipt->refresh());
    }

    private function paymentAccount(): Account
    {
        return Account::query()->where('is_payment_account', true)->orderBy('code')->firstOrFail();
    }
}
