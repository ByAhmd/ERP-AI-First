<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\CreditNoteReason;
use App\Enums\InvoiceSubtype;
use App\Models\Company;
use App\Models\Contact;
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
use App\Services\Sales\Exceptions\CreditNoteRejected;
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
 * Standard versus simplified tax invoices.
 *
 * The two are different legal instruments, not a formatting choice: a standard
 * invoice identifies the buyer and is what a VAT-registered customer recovers
 * input VAT with; a simplified one identifies nobody and is for consumers.
 * Getting the classification wrong is a compliance failure for the seller and
 * an unrecoverable cost for the buyer — and nothing about the ledger entry
 * would look different, so the rules live in guards, not arithmetic.
 */
final class InvoiceSubtypeTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Contact $consumer;

    private Contact $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->consumer = Contact::create(['contact_name' => 'مستهلك فرد']);
        $this->business = Contact::create([
            'contact_name' => 'مؤسسة النخيل',
            'tax_number' => '300000000000003',
        ]);
    }

    #[Test]
    public function the_subtype_codes_are_zatca_codes(): void
    {
        // The stored value is what serialises into KSA-2, so it is ZATCA's
        // own code, not a name of ours.
        $this->assertSame('01', InvoiceSubtype::Standard->value);
        $this->assertSame('02', InvoiceSubtype::Simplified->value);
        $this->assertSame('0100000', InvoiceSubtype::Standard->transactionCode());
        $this->assertSame('0200000', InvoiceSubtype::Simplified->transactionCode());
    }

    #[Test]
    public function the_default_follows_the_customer(): void
    {
        $this->assertSame(InvoiceSubtype::Standard, InvoiceSubtype::forContact($this->business));
        $this->assertSame(InvoiceSubtype::Simplified, InvoiceSubtype::forContact($this->consumer));
        $this->assertSame(InvoiceSubtype::Simplified, InvoiceSubtype::forContact(null));
    }

    #[Test]
    public function a_simplified_invoice_to_a_registered_buyer_is_refused(): void
    {
        // It would hand the buyer a document they can recover nothing with.
        $invoice = $this->draftInvoice($this->business, InvoiceSubtype::Simplified);

        $this->expectException(InvoiceRuleViolation::class);

        app(SalesInvoicePoster::class)->approve($invoice);
    }

    #[Test]
    public function a_standard_invoice_to_a_consumer_is_allowed(): void
    {
        // The reverse is legal: nothing stops identifying a consumer fully.
        $approved = app(SalesInvoicePoster::class)->approve(
            $this->draftInvoice($this->consumer, InvoiceSubtype::Standard),
        );

        $this->assertTrue($approved->isApproved());
        $this->assertSame(InvoiceSubtype::Standard, $approved->subtype);
    }

    #[Test]
    public function a_credit_note_inherits_its_parents_subtype_whatever_the_form_said(): void
    {
        $invoice = app(SalesInvoicePoster::class)->approve(
            $this->draftInvoice($this->business, InvoiceSubtype::Standard),
        );

        $note = $this->draftNote($this->business, $invoice, InvoiceSubtype::Simplified);

        // The model corrected it on save: derived, never chosen.
        $this->assertSame(InvoiceSubtype::Standard, $note->refresh()->subtype);
    }

    #[Test]
    public function a_simplified_invoice_stays_creditable_after_the_buyer_registers(): void
    {
        // The buyer registering for VAT later must not strand their history:
        // the simplified invoice is corrected by a simplified note that
        // references it. The guard applies only to external-original notes.
        $invoice = app(SalesInvoicePoster::class)->approve(
            $this->draftInvoice($this->consumer, InvoiceSubtype::Simplified),
        );

        $this->consumer->update(['tax_number' => '300000000000099']);

        $note = $this->draftNote($this->consumer, $invoice, InvoiceSubtype::Simplified);
        $approved = app(CreditNotePoster::class)->approve($note);

        $this->assertTrue($approved->isApproved());
        $this->assertSame(InvoiceSubtype::Simplified, $approved->subtype);
    }

    #[Test]
    public function an_external_simplified_note_to_a_registered_buyer_is_refused(): void
    {
        $note = $this->draftNote($this->business, null, InvoiceSubtype::Simplified);

        $this->expectException(CreditNoteRejected::class);

        app(CreditNotePoster::class)->approve($note);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function draftInvoice(Contact $contact, InvoiceSubtype $subtype): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'subtype' => $subtype,
            'contact_id' => $contact->getKey(),
            'issue_date' => CarbonImmutable::parse('2026-03-15'),
            'due_date' => CarbonImmutable::parse('2026-04-15'),
            'supply_date' => CarbonImmutable::parse('2026-03-15'),
        ]);

        SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->getKey(),
            'product_id' => $this->product()->getKey(),
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
        ]);

        return app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh());
    }

    private function draftNote(Contact $contact, ?SalesInvoice $parent, InvoiceSubtype $subtype): SalesCreditNote
    {
        $note = SalesCreditNote::create([
            'reference' => app(CreditNotePoster::class)->nextReference(),
            'subtype' => $subtype,
            'contact_id' => $contact->getKey(),
            'parent_id' => $parent?->getKey(),
            'original_invoice_number' => $parent === null ? 'PAPER-2024-9' : $parent->reference,
            'issue_date' => CarbonImmutable::parse('2026-03-20'),
            'due_date' => CarbonImmutable::parse('2026-03-20'),
            'event_date' => CarbonImmutable::parse('2026-03-18'),
            'reason_code' => CreditNoteReason::GoodsReturn,
            'reason_text' => 'إرجاع بضاعة',
        ]);

        SalesCreditNoteItem::create([
            'sales_credit_note_id' => $note->getKey(),
            'product_id' => $this->product()->getKey(),
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
        ]);

        return app(CreditNoteRecalculator::class)->recalculate($note->refresh());
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
