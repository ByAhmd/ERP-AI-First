<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerReceipts\Pages\CreateCustomerReceipt;
use App\Filament\Resources\CustomerReceipts\Pages\EditCustomerReceipt;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Product;
use App\Models\ProductUnitType;
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
 * The receipt screen, driven as a person drives it.
 *
 * Same rationale as every other panel test here: the gap between a service
 * that works and a screen that reaches it differently is where every defect a
 * user actually hit has lived.
 */
final class CustomerReceiptPanelTest extends TestCase
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
    public function the_create_form_opens_with_a_reference_in_the_receipt_series(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CreateCustomerReceipt::class);

        $component->assertOk();
        $component->assertFormSet(['reference' => 'RCT-00001']);
    }

    #[Test]
    public function the_deposit_account_select_offers_only_payment_accounts(): void
    {
        // The template flags 1110 and 1120; receivable 1130 must not appear.
        Livewire::actingAs($this->admin)
            ->test(CreateCustomerReceipt::class)
            ->assertOk()
            ->assertSee('النقد في الصندوق')
            ->assertSee('النقد لدى البنوك')
            ->assertDontSee('1130 - الذمم المدينة');
    }

    #[Test]
    public function a_receipt_typed_into_the_screen_settles_its_invoice_end_to_end(): void
    {
        $invoice = $this->approvedInvoice();

        Livewire::actingAs($this->admin)
            ->test(CreateCustomerReceipt::class)
            ->fillForm([
                'reference' => 'RCT-TEST-1',
                'contact_id' => $this->customer->getKey(),
                'deposit_account_id' => $this->cashAccount()->getKey(),
                'receipt_date' => '2026-04-01',
                'amount' => 115,
                'allocations' => [
                    [
                        'sales_invoice_id' => $invoice->getKey(),
                        'amount' => 115,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $receipt = CustomerReceipt::query()->firstOrFail();

        $this->assertSame(DocumentStatus::Draft, $receipt->status);
        $this->assertCount(1, $receipt->allocations);

        Livewire::actingAs($this->admin)
            ->test(EditCustomerReceipt::class, ['record' => $receipt->getKey()])
            ->callAction('approve');

        $approved = $receipt->refresh();

        $this->assertSame(DocumentStatus::Approved, $approved->status);
        $this->assertNotNull($approved->journal_entry_id);
        $this->assertTrue($approved->journalEntry->isBalanced());
        $this->assertSame('0.0000', $approved->unallocatedAmount());
    }

    #[Test]
    public function an_over_allocation_is_reported_rather_than_thrown(): void
    {
        $invoice = $this->approvedInvoice();

        Livewire::actingAs($this->admin)
            ->test(CreateCustomerReceipt::class)
            ->fillForm([
                'reference' => 'RCT-OVER-1',
                'contact_id' => $this->customer->getKey(),
                'deposit_account_id' => $this->cashAccount()->getKey(),
                'receipt_date' => '2026-04-01',
                'amount' => 500,
                'allocations' => [
                    [
                        'sales_invoice_id' => $invoice->getKey(),
                        'amount' => 400,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $receipt = CustomerReceipt::query()->firstOrFail();

        // 400 > the invoice's 115 outstanding: refused with a message, and
        // nothing half-posted behind it.
        Livewire::actingAs($this->admin)
            ->test(EditCustomerReceipt::class, ['record' => $receipt->getKey()])
            ->callAction('approve')
            ->assertOk();

        $this->assertSame(DocumentStatus::Draft, $receipt->refresh()->status);
        $this->assertNull($receipt->journal_entry_id);
    }

    #[Test]
    public function an_approved_receipt_cannot_be_opened_for_editing(): void
    {
        $invoice = $this->approvedInvoice();

        Livewire::actingAs($this->admin)
            ->test(CreateCustomerReceipt::class)
            ->fillForm([
                'reference' => 'RCT-LOCK-1',
                'contact_id' => $this->customer->getKey(),
                'deposit_account_id' => $this->cashAccount()->getKey(),
                'receipt_date' => '2026-04-01',
                'amount' => 115,
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => 115],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $receipt = CustomerReceipt::query()->firstOrFail();

        Livewire::actingAs($this->admin)
            ->test(EditCustomerReceipt::class, ['record' => $receipt->getKey()])
            ->callAction('approve');

        Livewire::actingAs($this->admin)
            ->test(EditCustomerReceipt::class, ['record' => $receipt->getKey()])
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
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
        ]);

        return app(SalesInvoicePoster::class)->approve(
            app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh()),
        );
    }

    private function cashAccount(): Account
    {
        return Account::query()->where('code', '1110')->firstOrFail();
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
