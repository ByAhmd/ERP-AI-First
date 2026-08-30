<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Filament\Resources\SalesCreditNotes\Pages\CreateSalesCreditNote;
use App\Filament\Resources\SalesCreditNotes\Pages\EditSalesCreditNote;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The credit note screen, driven as a person drives it.
 *
 * Same rationale as the invoice's panel test: every defect a user actually hit
 * lived in the gap between a service that worked and a screen that reached it
 * differently — and the repeater found two on the invoice screen alone.
 */
final class CreditNotePanelTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Trading');
        $this->admin = $this->makeAdministrator($this->company, 'admin@acme.test');

        $this->actingInPanel($this->admin, $this->company);

        $this->makeChartOfAccounts($this->company);
        $this->makeFiscalYear($this->company, 2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->customer = Contact::create(['contact_name' => 'مؤسسة النخيل']);
    }

    #[Test]
    public function the_create_form_opens_with_a_reference_in_the_credit_note_series(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CreateSalesCreditNote::class);

        $component->assertOk();
        $component->assertFormSet(['reference' => 'CN-00001']);
    }

    #[Test]
    public function the_form_carries_the_zatca_fields_qoyod_does_not_show(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSalesCreditNote::class)
            ->assertOk()
            ->assertSee(__('sales.credit_notes.fields.original_invoice_number'))
            ->assertSee(__('sales.credit_notes.fields.reason_code'))
            ->assertSee(__('sales.credit_notes.fields.event_date'));
    }

    #[Test]
    public function a_note_typed_into_the_screen_credits_its_invoice_end_to_end(): void
    {
        $invoice = $this->approvedInvoice();

        Livewire::actingAs($this->admin)
            ->test(CreateSalesCreditNote::class)
            ->fillForm([
                'reference' => 'CN-TEST-1',
                'contact_id' => $this->customer->getKey(),
                'parent_id' => $invoice->getKey(),
                'original_invoice_number' => $invoice->reference,
                'issue_date' => '2026-03-20',
                'due_date' => '2026-03-20',
                'event_date' => '2026-03-18',
                'reason_code' => CreditNoteReason::GoodsReturn->value,
                'reason_text' => 'إرجاع بضاعة',
                'items' => [
                    [
                        'product_id' => $this->product()->getKey(),
                        'product_description' => null,
                        'quantity' => 3,
                        'unit_price' => 115,
                        'is_inclusive' => true,
                        'discount_value' => 0,
                        'discount_type' => 'percentage',
                        'tax_id' => Tax::query()->where('is_default', true)->value('id'),
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $note = SalesCreditNote::query()->firstOrFail();

        $this->assertSame(DocumentStatus::Draft, $note->status);
        $this->assertSame('345.0000', $note->total);

        // The screen offers a product column, as Qoyod's does; the line link
        // that makes per-line guards possible is resolved from it.
        $this->assertNotNull($note->items->first()->sales_invoice_item_id);

        // And the rate came from the invoice line, not the tax record.
        $this->assertSame('15.0000', (string) $note->items->first()->tax_rate);

        Livewire::actingAs($this->admin)
            ->test(EditSalesCreditNote::class, ['record' => $note->getKey()])
            ->callAction('approve');

        $approved = $note->refresh();

        $this->assertSame(DocumentStatus::Approved, $approved->status);
        $this->assertNotNull($approved->journal_entry_id);
        $this->assertTrue($approved->journalEntry->isBalanced());
    }

    #[Test]
    public function an_over_credit_is_reported_rather_than_thrown(): void
    {
        $invoice = $this->approvedInvoice();

        $note = $this->draftNoteAgainst($invoice, quantity: '9');

        Livewire::actingAs($this->admin)
            ->test(EditSalesCreditNote::class, ['record' => $note->getKey()])
            ->callAction('approve')
            ->assertOk();

        // Refused, reported, and nothing half-posted behind it.
        $this->assertSame(DocumentStatus::Draft, $note->refresh()->status);
        $this->assertNull($note->journal_entry_id);
    }

    #[Test]
    public function an_approved_note_cannot_be_opened_for_editing(): void
    {
        $invoice = $this->approvedInvoice();
        $note = $this->draftNoteAgainst($invoice, quantity: '1');

        Livewire::actingAs($this->admin)
            ->test(EditSalesCreditNote::class, ['record' => $note->getKey()])
            ->callAction('approve');

        Livewire::actingAs($this->admin)
            ->test(EditSalesCreditNote::class, ['record' => $note->getKey()])
            ->assertRedirect();
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function approvedInvoice(): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => CarbonImmutable::parse('2026-03-15'),
            'due_date' => CarbonImmutable::parse('2026-04-15'),
            'supply_date' => CarbonImmutable::parse('2026-03-15'),
        ]);

        SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->getKey(),
            'product_id' => $this->product()->getKey(),
            'quantity' => '3',
            'unit_price' => '115.00',
            'is_inclusive' => true,
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
        ]);

        return app(SalesInvoicePoster::class)->approve(
            app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh()),
        );
    }

    private function draftNoteAgainst(SalesInvoice $invoice, string $quantity): SalesCreditNote
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSalesCreditNote::class)
            ->fillForm([
                'reference' => 'CN-FIX-'.$quantity,
                'contact_id' => $this->customer->getKey(),
                'parent_id' => $invoice->getKey(),
                'original_invoice_number' => $invoice->reference,
                'issue_date' => '2026-03-20',
                'due_date' => '2026-03-20',
                'event_date' => '2026-03-18',
                'reason_code' => CreditNoteReason::GoodsReturn->value,
                'reason_text' => 'إرجاع بضاعة',
                'items' => [
                    [
                        'product_id' => $this->product()->getKey(),
                        'product_description' => null,
                        'quantity' => $quantity,
                        'unit_price' => 115,
                        'is_inclusive' => true,
                        'discount_value' => 0,
                        'discount_type' => 'percentage',
                        'tax_id' => Tax::query()->where('is_default', true)->value('id'),
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return SalesCreditNote::query()->latest('created_at')->firstOrFail();
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'كرسي مكتب',
            'name_en' => 'Office Chair',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'selling_price' => '115',
        ]);
    }
}
