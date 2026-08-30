<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\QuotationStatus;
use App\Filament\Resources\SalesQuotations\Pages\CreateSalesQuotation;
use App\Filament\Resources\SalesQuotations\Pages\EditSalesQuotation;
use App\Filament\Resources\SalesQuotations\Pages\ListSalesQuotations;
use App\Filament\Resources\SalesQuotations\Pages\ViewSalesQuotation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SalesInvoice;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\SalesQuotationApprover;
use App\Services\Sales\SalesQuotationRecalculator;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The quotation screens, driven as a person drives them.
 *
 * The service tests prove the lifecycle; these prove the screens reach it —
 * the seeded QTE reference, the approve and convert actions, and the redirect
 * that keeps anything past draft out of the edit page.
 */
final class SalesQuotationPanelTest extends TestCase
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
    public function the_list_page_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListSalesQuotations::class)
            ->assertOk();
    }

    #[Test]
    public function the_create_form_opens_with_a_reference_in_the_quotation_series(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CreateSalesQuotation::class);

        $component->assertOk();
        $component->assertFormSet(['reference' => 'QTE-00001']);
        $component->assertSee(__('sales.quotations.fields.expiry_date'));
    }

    #[Test]
    public function approving_from_the_edit_page_fixes_the_offer_without_posting(): void
    {
        $quotation = $this->draftQuotation();

        Livewire::actingAs($this->admin)
            ->test(EditSalesQuotation::class, ['record' => $quotation->getKey()])
            ->callAction('approve')
            ->assertNotified(__('sales.quotations.actions.approved'));

        $this->assertSame(QuotationStatus::Approved, $quotation->refresh()->status);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    #[Test]
    public function the_edit_page_redirects_anything_past_draft_to_the_view(): void
    {
        $quotation = $this->draftQuotation();
        app(SalesQuotationApprover::class)->approve($quotation);

        Livewire::actingAs($this->admin)
            ->test(EditSalesQuotation::class, ['record' => $quotation->getKey()])
            ->assertRedirect();
    }

    #[Test]
    public function converting_from_the_view_page_creates_the_draft_invoice(): void
    {
        $quotation = $this->draftQuotation();
        app(SalesQuotationApprover::class)->approve($quotation);

        Livewire::actingAs($this->admin)
            ->test(ViewSalesQuotation::class, ['record' => $quotation->getKey()])
            ->callAction('convert');

        $invoice = SalesInvoice::query()->firstOrFail();

        $this->assertSame($quotation->getKey(), $invoice->quotation_id);
        $this->assertSame(QuotationStatus::Invoiced, $quotation->refresh()->status);
        $this->assertTrue($invoice->isDraft());
    }

    #[Test]
    public function cancelling_from_the_view_page_is_terminal(): void
    {
        $quotation = $this->draftQuotation();
        app(SalesQuotationApprover::class)->approve($quotation);

        Livewire::actingAs($this->admin)
            ->test(ViewSalesQuotation::class, ['record' => $quotation->getKey()])
            ->callAction('cancel')
            ->assertNotified(__('sales.quotations.actions.cancelled'));

        $this->assertSame(QuotationStatus::Cancelled, $quotation->refresh()->status);
    }

    // ------------------------------------------------------------------ helpers

    private function draftQuotation(): SalesQuotation
    {
        $quotation = SalesQuotation::create([
            'reference' => app(SalesQuotationApprover::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => today(),
            'expiry_date' => today()->addDays(30),
        ]);

        SalesQuotationItem::create([
            'sales_quotation_id' => $quotation->getKey(),
            'product_name' => 'خدمة استشارية',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
        ]);

        return app(SalesQuotationRecalculator::class)->recalculate($quotation->refresh());
    }
}
