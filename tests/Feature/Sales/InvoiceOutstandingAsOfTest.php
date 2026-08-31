<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\CreditNotePoster;
use App\Services\Sales\CreditNoteRecalculator;
use App\Services\Sales\CustomerReceiptPoster;
use App\Services\Sales\InvoiceOutstanding;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The as-of date bound on invoice outstanding.
 *
 * "As of June 30" must mean June 30 forever: a report run in August must show
 * the same June figures it showed in July, whatever has been received since.
 * And the date that bounds an allocation is its EFFECTIVE date — the
 * receipt's own date when it settled at approval, the allocation entry's
 * date when an advance was applied later. Bounding on the receipt date alone
 * backdates July's advance-allocations into June, which is the one mistake
 * this file exists to make loud.
 */
final class InvoiceOutstandingAsOfTest extends TestCase
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

    /** The drift guard: dated at today, the bound must change nothing. */
    #[Test]
    public function as_of_today_equals_the_undated_figure_to_the_cent(): void
    {
        $invoice = $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-03-01'));

        $this->receiptAllocated($invoice, '400.0000', CarbonImmutable::parse('2026-03-10'));
        $this->approvedCreditNote($invoice, '100.00', CarbonImmutable::parse('2026-03-12'));

        $service = app(InvoiceOutstanding::class);
        $invoice = $invoice->refresh();

        $this->assertSame(
            $service->outstanding($invoice),
            $service->outstanding($invoice, CarbonImmutable::now()),
        );

        // And the grouped path agrees with the per-invoice path.
        $open = $service->openByContact(CarbonImmutable::now());

        $this->assertSame(
            $service->outstanding($invoice),
            $open[$this->customer->getKey()]['amount'],
        );
    }

    /** June 30 stays June 30, however late the report is run. */
    #[Test]
    public function a_later_receipt_does_not_rewrite_an_earlier_snapshot(): void
    {
        $invoice = $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));

        $this->receiptAllocated($invoice, '1000.0000', CarbonImmutable::parse('2026-07-15'));

        $service = app(InvoiceOutstanding::class);
        $invoice = $invoice->refresh();

        // As of June 30 the money had not arrived; as of July 31 it had.
        $this->assertSame('1000.0000', $service->outstanding($invoice, CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('0.0000', $service->outstanding($invoice, CarbonImmutable::parse('2026-07-31')));

        $june = $service->openByContact(CarbonImmutable::parse('2026-06-30'));
        $july = $service->openByContact(CarbonImmutable::parse('2026-07-31'));

        $this->assertSame('1000.0000', $june[$this->customer->getKey()]['amount']);
        $this->assertArrayNotHasKey($this->customer->getKey(), $july);
    }

    /**
     * The COALESCE test: an advance received in June but applied in July
     * leaves the invoice open at June 30 — the allocation's effective date is
     * its own entry's date, not the receipt's.
     */
    #[Test]
    public function an_advance_applied_later_settles_at_the_allocation_date_not_the_receipt_date(): void
    {
        $invoice = $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-05-20'));

        // Approved unallocated in June — pure advance.
        $receipt = CustomerReceipt::create([
            'reference' => app(CustomerReceiptPoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'deposit_account_id' => $this->paymentAccount()->getKey(),
            'receipt_date' => CarbonImmutable::parse('2026-06-01'),
            'amount' => '1000.00',
        ]);
        app(CustomerReceiptPoster::class)->approve($receipt);

        // Applied in July, as its own accounting event dated July 10.
        app(CustomerReceiptPoster::class)->allocate(
            $receipt,
            $invoice,
            '1000.0000',
            CarbonImmutable::parse('2026-07-10'),
        );

        $service = app(InvoiceOutstanding::class);
        $invoice = $invoice->refresh();

        $this->assertSame('1000.0000', $service->outstanding($invoice, CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('0.0000', $service->outstanding($invoice, CarbonImmutable::parse('2026-07-31')));

        // The advance figure moves the opposite way across the same boundary.
        $this->assertSame('1000.0000', $service->unallocatedAdvancesTotal(CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('0.0000', $service->unallocatedAdvancesTotal(CarbonImmutable::parse('2026-07-31')));
    }

    #[Test]
    public function credit_notes_bound_on_their_issue_date(): void
    {
        $invoice = $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));

        $this->approvedCreditNote($invoice, '200.00', CarbonImmutable::parse('2026-07-05'));

        $service = app(InvoiceOutstanding::class);
        $invoice = $invoice->refresh();

        $this->assertSame('1000.0000', $service->outstanding($invoice, CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('800.0000', $service->outstanding($invoice, CarbonImmutable::parse('2026-07-31')));
    }

    #[Test]
    public function standalone_credit_notes_appear_in_their_own_total_not_in_any_invoice(): void
    {
        $invoice = $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));

        // A note with no parent — credits receivable, reduces no invoice.
        $note = SalesCreditNote::create([
            'reference' => app(CreditNotePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'original_invoice_number' => 'OLD-SYS-12',
            'issue_date' => CarbonImmutable::parse('2026-06-10'),
            'due_date' => CarbonImmutable::parse('2026-06-10'),
            'event_date' => CarbonImmutable::parse('2026-06-10'),
            'reason_code' => 'cancellation',
            'reason_text' => 'إلغاء',
        ]);

        SalesCreditNoteItem::create([
            'sales_credit_note_id' => $note->getKey(),
            'product_name' => 'مرتجع',
            'quantity' => '1',
            'unit_price' => '150.00',
            'tax_id' => Tax::query()->where('category', TaxCategory::ZeroRated)->value('id'),
        ]);

        app(CreditNotePoster::class)->approve(
            app(CreditNoteRecalculator::class)->recalculate($note->refresh()),
        );

        $service = app(InvoiceOutstanding::class);

        $this->assertSame('1000.0000', $service->outstanding($invoice->refresh(), CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('150.0000', $service->unappliedCreditNotesTotal(CarbonImmutable::parse('2026-06-30')));
        // Dated before its issue, the note does not exist yet.
        $this->assertSame('0.0000', $service->unappliedCreditNotesTotal(CarbonImmutable::parse('2026-06-05')));
    }

    #[Test]
    public function another_companys_documents_are_invisible_in_every_term(): void
    {
        $this->approvedInvoice('1000.00', CarbonImmutable::parse('2026-06-01'));

        $service = app(InvoiceOutstanding::class);
        $open = $service->openByContact(CarbonImmutable::parse('2026-06-30'));
        $this->assertCount(1, $open);

        // Switch context to a second company: nothing bleeds through.
        $other = $this->makeAccountingCompany(2026);

        $this->assertSame([], $service->openByContact(CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('0.0000', $service->unappliedCreditNotesTotal(CarbonImmutable::parse('2026-06-30')));
        $this->assertSame('0.0000', $service->unallocatedAdvancesTotal(CarbonImmutable::parse('2026-06-30')));
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
            'product_id' => $this->product()->getKey(),
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

    private function approvedCreditNote(SalesInvoice $invoice, string $grossTotal, CarbonImmutable $date): SalesCreditNote
    {
        $note = SalesCreditNote::create([
            'reference' => app(CreditNotePoster::class)->nextReference(),
            'contact_id' => $invoice->contact_id,
            'parent_id' => $invoice->getKey(),
            'original_invoice_number' => $invoice->reference,
            'issue_date' => $date,
            'due_date' => $date,
            'event_date' => $date,
            'reason_code' => 'cancellation',
            'reason_text' => 'إلغاء',
        ]);

        SalesCreditNoteItem::create([
            'sales_credit_note_id' => $note->getKey(),
            'product_id' => $this->product()->getKey(),
            'quantity' => '1',
            'unit_price' => $grossTotal,
            'is_inclusive' => true,
        ]);

        return app(CreditNotePoster::class)->approve(
            app(CreditNoteRecalculator::class)->recalculate($note->refresh()),
        );
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'خدمة استشارية',
            'name_en' => 'Consulting',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'selling_price' => '100',
        ]);
    }

    private function paymentAccount(): Account
    {
        return Account::query()->where('is_payment_account', true)->orderBy('code')->firstOrFail();
    }
}
