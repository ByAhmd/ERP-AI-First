<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceSubtype;
use App\Enums\QuotationStatus;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\SalesInvoice;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\Tax;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\Exceptions\QuotationRuleViolation;
use App\Services\Sales\QuotationConverter;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesQuotationApprover;
use App\Services\Sales\SalesQuotationRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The quotation lifecycle, and the one invariant that makes it safe.
 *
 * A quotation is a commercial document: at no status does it touch the ledger.
 * Every failure this file guards against is silent — a quotation counted as a
 * receivable, a March tax rate billed in June, two invoices from one offer, a
 * quotation series eating the gapless invoice series. None of them would
 * crash; all of them would be wrong numbers signed by the system.
 */
final class QuotationLifecycleTest extends TestCase
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

    /** The cross-cutting invariant, and the reason the table has no journal_entry_id. */
    #[Test]
    public function nothing_posts_until_the_converted_invoice_is_approved(): void
    {
        $quotation = $this->draftQuotation([['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]);

        $before = JournalEntry::query()->count();

        $approved = app(SalesQuotationApprover::class)->approve($quotation);

        $this->assertSame(QuotationStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame($before, JournalEntry::query()->count());

        $invoice = app(QuotationConverter::class)->convert($approved);

        // Conversion creates a draft — still nothing in the books.
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($before, JournalEntry::query()->count());

        app(SalesInvoicePoster::class)->approve($invoice);

        // Exactly one entry, and it is the invoice's.
        $this->assertSame($before + 1, JournalEntry::query()->count());
        $this->assertSame(QuotationStatus::Invoiced, $approved->refresh()->status);
    }

    #[Test]
    public function quotations_number_from_their_own_series_and_leave_the_invoice_counter_untouched(): void
    {
        $reference = app(SalesQuotationApprover::class)->nextReference();

        $this->assertSame('QTE-00001', $reference);

        // The gapless ZATCA invoice series must show no quotation-shaped hole.
        $this->assertSame('INV-00001', app(SalesInvoicePoster::class)->nextReference());
    }

    #[Test]
    public function conversion_carries_the_quoted_price_and_rebills_tax_at_todays_rate(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '2', 'unit_price' => '100.00']]);

        // The law changes between quoting and billing — the 5%→15% trap.
        $tax = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();
        $tax->forceFill(['rate' => '5.00'])->save();

        $invoice = app(QuotationConverter::class)->convert($quotation);
        $line = $invoice->items()->firstOrFail();

        // The commercial agreement is carried verbatim...
        $this->assertSame('100.0000', (string) $line->unit_price);
        $this->assertSame('2.0000', (string) $line->quantity);

        // ...and the fiscal facts are the invoice's own: today's rate, not the
        // quotation's 15% snapshot.
        $this->assertSame('5.0000', (string) $line->tax_rate);
        $this->assertSame('10.0000', (string) $invoice->tax_total);
        $this->assertSame('200.0000', (string) $invoice->subtotal_net);

        // The quotation's own snapshot is untouched: it keeps saying what it
        // said when it was offered.
        $this->assertSame('15.0000', (string) $quotation->items()->firstOrFail()->tax_rate);
    }

    #[Test]
    public function conversion_resolves_the_subtype_from_the_customers_current_registration(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        // The customer VAT-registers between quoting and billing.
        $this->customer->forceFill(['tax_number' => '310123456700003'])->save();

        $invoice = app(QuotationConverter::class)->convert($quotation);

        $this->assertSame(InvoiceSubtype::Standard, $invoice->subtype);
    }

    #[Test]
    public function a_quotation_converts_exactly_once(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        $invoice = app(QuotationConverter::class)->convert($quotation);

        try {
            app(QuotationConverter::class)->convert($quotation->refresh());
            $this->fail('A second conversion should refuse.');
        } catch (QuotationRuleViolation $refusal) {
            // The refusal names the invoice already raised from it.
            $this->assertStringContainsString($invoice->reference, $refusal->getMessage());
        }
    }

    /** The database backstop for the race the status check cannot see. */
    #[Test]
    public function the_unique_index_refuses_a_second_invoice_for_the_same_quotation(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        app(QuotationConverter::class)->convert($quotation);

        $this->expectException(QueryException::class);

        SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'quotation_id' => $quotation->getKey(),
            'issue_date' => today(),
            'due_date' => today(),
            'supply_date' => today(),
        ]);
    }

    #[Test]
    public function deleting_the_still_draft_converted_invoice_releases_the_quotation(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        $invoice = app(QuotationConverter::class)->convert($quotation);

        $this->assertSame(QuotationStatus::Invoiced, $quotation->refresh()->status);

        $invoice->delete();

        // Back to Approved, and the unique index now permits an honest
        // re-conversion.
        $this->assertSame(QuotationStatus::Approved, $quotation->refresh()->status);

        $second = app(QuotationConverter::class)->convert($quotation->refresh());

        $this->assertSame($quotation->getKey(), $second->quotation_id);
    }

    #[Test]
    public function only_an_approved_quotation_converts(): void
    {
        $draft = $this->draftQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        try {
            app(QuotationConverter::class)->convert($draft);
            $this->fail('A draft should not convert.');
        } catch (QuotationRuleViolation) {
            $this->assertSame(QuotationStatus::Draft, $draft->refresh()->status);
        }

        $cancelled = app(SalesQuotationApprover::class)->cancel(
            $this->draftQuotation([['quantity' => '1', 'unit_price' => '100.00']]),
        );

        $this->expectException(QuotationRuleViolation::class);

        app(QuotationConverter::class)->convert($cancelled);
    }

    #[Test]
    public function an_expired_quotation_still_converts(): void
    {
        // Qoyod converts any approved quotation; the guard is the human
        // reviewing the draft, and the UI's warning. Accepted on the last day,
        // converted two days later, must not force a cancel-and-rekey.
        $quotation = $this->approvedQuotation(
            [['quantity' => '1', 'unit_price' => '100.00']],
            issueDate: today()->subDays(40),
            expiryDate: today()->subDays(10),
        );

        $this->assertTrue($quotation->isExpired());

        $invoice = app(QuotationConverter::class)->convert($quotation);

        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        // The invoice's dates are its own, not the lapsed offer's.
        $this->assertTrue($invoice->issue_date->isToday());
    }

    #[Test]
    public function a_deleted_tax_refuses_conversion_rather_than_zero_rating(): void
    {
        // A bespoke rate rather than the template's: system taxes refuse
        // deletion outright, and this guard exists for the ones that don't.
        $bespoke = Tax::create([
            'name' => 'ضريبة مؤقتة',
            'category' => TaxCategory::Standard,
            'rate' => '15.00',
            'account_id' => Tax::query()->where('category', TaxCategory::Standard)->firstOrFail()->account_id,
        ]);

        $quotation = $this->draftQuotation([['quantity' => '1', 'unit_price' => '100.00']]);
        $quotation->items()->update(['tax_id' => $bespoke->getKey()]);
        $quotation = app(SalesQuotationApprover::class)->approve(
            app(SalesQuotationRecalculator::class)->recalculate($quotation->refresh()),
        );

        // Taxes soft-delete, and the recalculator's fallback for a tax it
        // cannot find is 0% — the silent zero-rating this guard exists for.
        $bespoke->delete();

        try {
            app(QuotationConverter::class)->convert($quotation);
            $this->fail('A quotation whose tax is gone should refuse to convert.');
        } catch (QuotationRuleViolation $refusal) {
            $this->assertStringContainsString('لم تعد متاحة', $refusal->getMessage());
            // Nothing half-created.
            $this->assertSame(0, SalesInvoice::query()->count());
            $this->assertSame(QuotationStatus::Approved, $quotation->refresh()->status);
        }
    }

    #[Test]
    public function conversion_recovers_the_customer_check_before_the_invoice_exists(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->customer->forceFill(['status' => ContactStatus::Inactive])->save();

        try {
            app(QuotationConverter::class)->convert($quotation);
            $this->fail('An inactive customer should refuse at conversion, not two screens later.');
        } catch (QuotationRuleViolation) {
            $this->assertSame(0, SalesInvoice::query()->count());
        }
    }

    #[Test]
    public function approval_fixes_the_offer_and_guards_it(): void
    {
        $empty = SalesQuotation::create([
            'reference' => app(SalesQuotationApprover::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => today(),
            'expiry_date' => today()->addDays(30),
        ]);

        try {
            app(SalesQuotationApprover::class)->approve($empty);
            $this->fail('An empty quotation should not approve.');
        } catch (QuotationRuleViolation) {
            $this->assertSame(QuotationStatus::Draft, $empty->refresh()->status);
        }

        $approved = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->expectException(QuotationRuleViolation::class);

        app(SalesQuotationApprover::class)->approve($approved);
    }

    #[Test]
    public function an_invoiced_quotation_cannot_be_cancelled(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        app(QuotationConverter::class)->convert($quotation);

        $this->expectException(QuotationRuleViolation::class);

        app(SalesQuotationApprover::class)->cancel($quotation->refresh());
    }

    #[Test]
    public function an_approved_quotation_is_frozen_against_tax_changes(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        $tax = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();
        $tax->forceFill(['rate' => '5.00'])->save();

        // Recalculating an approved quotation is a no-op: the offer the
        // customer holds does not restate itself.
        app(SalesQuotationRecalculator::class)->recalculate($quotation->refresh());

        $this->assertSame('15.0000', (string) $quotation->items()->firstOrFail()->tax_rate);
        $this->assertSame('115.0000', (string) $quotation->refresh()->total);
    }

    #[Test]
    public function the_quotation_keeps_its_snapshot_when_the_product_is_renamed(): void
    {
        $quotation = $this->approvedQuotation([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->product()->forceFill(['name' => 'اسم جديد تمامًا'])->save();

        $this->assertSame('كرسي مكتب', $quotation->items()->firstOrFail()->product_name);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedQuotation(
        array $lines,
        ?CarbonInterface $issueDate = null,
        ?CarbonInterface $expiryDate = null,
    ): SalesQuotation {
        return app(SalesQuotationApprover::class)->approve(
            $this->draftQuotation($lines, $issueDate, $expiryDate),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftQuotation(
        array $lines,
        ?CarbonInterface $issueDate = null,
        ?CarbonInterface $expiryDate = null,
    ): SalesQuotation {
        $quotation = SalesQuotation::create([
            'reference' => app(SalesQuotationApprover::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => $issueDate ?? today(),
            'expiry_date' => $expiryDate ?? today()->addDays(30),
        ]);

        foreach ($lines as $line) {
            SalesQuotationItem::create([
                'sales_quotation_id' => $quotation->getKey(),
                'product_id' => $this->product()->getKey(),
                'product_name' => 'كرسي مكتب',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'tax_id' => Tax::query()->where('category', $line['category'] ?? TaxCategory::Standard)->value('id'),
            ]);
        }

        return app(SalesQuotationRecalculator::class)->recalculate($quotation->refresh());
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
}
