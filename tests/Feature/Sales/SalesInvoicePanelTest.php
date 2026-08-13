<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\ContactStatus;
use App\Enums\DocumentStatus;
use App\Filament\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\SalesInvoices\Pages\EditSalesInvoice;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\SalesInvoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The invoice screen, driven as a person drives it.
 *
 * The service layer is thoroughly covered, which is exactly why this exists:
 * every defect a user of this application has actually hit — the unset line
 * number, the unbound permission team, the enum cast on the tax form — lived in
 * the gap between a service that worked and a screen that reached it
 * differently.
 */
final class SalesInvoicePanelTest extends TestCase
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
    public function the_create_form_opens_with_a_reference_already_allocated(): void
    {
        // Qoyod shows one from the moment the form opens; a clerk refers to the
        // invoice they are working on before it is saved.
        $component = Livewire::actingAs($this->admin)->test(CreateSalesInvoice::class);

        $component->assertOk();
        $component->assertFormSet(['reference' => 'INV-00001']);
    }

    #[Test]
    public function the_line_table_carries_qoyods_columns(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSalesInvoice::class)
            ->assertOk()
            ->assertSee(__('sales.invoices.items.product'))
            ->assertSee(__('sales.invoices.items.quantity'))
            ->assertSee(__('sales.invoices.items.unit_price'))
            ->assertSee(__('sales.invoices.items.is_inclusive'))
            ->assertSee(__('sales.invoices.items.discount'))
            ->assertSee(__('sales.invoices.items.net'))
            ->assertSee(__('sales.invoices.items.tax_amount'))
            ->assertSee(__('sales.invoices.items.line_total'));
    }

    #[Test]
    public function an_invoice_typed_into_the_screen_saves_as_a_draft_with_resolved_totals(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSalesInvoice::class)
            ->fillForm($this->formData([
                ['quantity' => 3, 'unit_price' => 115, 'is_inclusive' => true],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = SalesInvoice::query()->firstOrFail();

        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        // Totals are derived after the lines exist, never taken from the form.
        $this->assertSame('300.0000', $invoice->subtotal_net);
        $this->assertSame('45.0000', $invoice->tax_total);
        $this->assertSame('345.0000', $invoice->total);
        $this->assertCount(1, $invoice->items);
    }

    #[Test]
    public function a_draft_saved_from_the_screen_reaches_no_ledger(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSalesInvoice::class)
            ->fillForm($this->formData([['quantity' => 1, 'unit_price' => 100]]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(SalesInvoice::query()->firstOrFail()->journal_entry_id);
    }

    #[Test]
    public function approving_from_the_screen_posts_it(): void
    {
        $invoice = $this->draftThroughTheScreen();

        Livewire::actingAs($this->admin)
            ->test(EditSalesInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('approve');

        $approved = $invoice->refresh();

        $this->assertSame(DocumentStatus::Approved, $approved->status);
        $this->assertNotNull($approved->journal_entry_id);
        $this->assertTrue($approved->journalEntry->isBalanced());
    }

    #[Test]
    public function a_refusal_is_reported_rather_than_thrown_at_the_reader(): void
    {
        $invoice = $this->draftThroughTheScreen();
        $this->customer->update(['status' => ContactStatus::Inactive]);

        Livewire::actingAs($this->admin)
            ->test(EditSalesInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('approve')
            ->assertOk();

        // Still a draft, and no half-written ledger entry behind it.
        $this->assertSame(DocumentStatus::Draft, $invoice->refresh()->status);
        $this->assertNull($invoice->journal_entry_id);
    }

    #[Test]
    public function an_approved_invoice_cannot_be_opened_for_editing(): void
    {
        $invoice = $this->draftThroughTheScreen();

        Livewire::actingAs($this->admin)
            ->test(EditSalesInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('approve');

        // The table offers a view action instead, but a URL can be typed.
        Livewire::actingAs($this->admin)
            ->test(EditSalesInvoice::class, ['record' => $invoice->getKey()])
            ->assertRedirect();
    }

    #[Test]
    public function choosing_a_product_copies_its_price_and_tax_onto_the_line(): void
    {
        // Copies, not references: the line must survive the product being
        // re-priced tomorrow.
        $product = $this->product();

        Livewire::actingAs($this->admin)
            ->test(CreateSalesInvoice::class)
            ->fillForm($this->formData([['quantity' => 1, 'unit_price' => 0]]))
            ->set('data.items.0.product_id', $product->getKey())
            ->assertFormSet(fn (array $state): bool => $state['items'][array_key_first($state['items'])]['unit_price'] === '450.0000'
                || (float) $state['items'][array_key_first($state['items'])]['unit_price'] === 450.0);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formData(array $lines): array
    {
        $standardTax = Tax::query()->where('is_default', true)->value('id');
        $product = $this->product();

        return [
            'reference' => 'INV-TEST-1',
            'contact_id' => $this->customer->getKey(),
            'issue_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'supply_date' => '2026-03-15',
            'items' => array_map(static fn (array $line): array => [
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'product_description' => null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'discount_value' => 0,
                'discount_type' => 'percentage',
                'tax_id' => $standardTax,
            ], $lines),
        ];
    }

    private function draftThroughTheScreen(): SalesInvoice
    {
        Livewire::actingAs($this->admin)
            ->test(CreateSalesInvoice::class)
            ->fillForm($this->formData([['quantity' => 3, 'unit_price' => 115, 'is_inclusive' => true]]))
            ->call('create')
            ->assertHasNoFormErrors();

        return SalesInvoice::query()->firstOrFail();
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'كرسي مكتب',
            'name_en' => 'Office Chair',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'selling_price' => '450',
        ]);
    }
}
