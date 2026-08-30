<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Enums\SystemAccount;
use App\Filament\Resources\SupplierPayments\Pages\CreateSupplierPayment;
use App\Filament\Resources\SupplierPayments\Pages\EditSupplierPayment;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Filament\Resources\SupplierPayments\Pages\ViewSupplierPayment;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SupplierPayment;
use App\Models\Tax;
use App\Models\User;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\BillOutstanding;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Purchases\SupplierPaymentPoster;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The payment voucher screens, driven as a person drives them.
 */
final class SupplierPaymentPanelTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Contact $supplier;

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

        $this->supplier = Contact::create([
            'contact_name' => 'شركة التوريدات الأولى',
            'type' => ContactType::Supplier,
        ]);
    }

    #[Test]
    public function the_list_page_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListSupplierPayments::class)
            ->assertOk();
    }

    #[Test]
    public function the_create_form_opens_with_a_reference_in_the_voucher_series(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CreateSupplierPayment::class);

        $component->assertOk();
        $component->assertFormSet(['reference' => 'PYT-00001']);
        $component->assertSee(__('purchases.payments.fields.payment_account'));
        $component->assertSee(__('purchases.payments.allocations.title'));
    }

    #[Test]
    public function approving_from_the_screen_posts_and_settles_the_bill(): void
    {
        $bill = $this->approvedBill();

        Livewire::actingAs($this->admin)
            ->test(CreateSupplierPayment::class)
            ->fillForm([
                'reference' => 'PYT-TEST-1',
                'contact_id' => $this->supplier->getKey(),
                'payment_account_id' => $this->paymentAccount()->getKey(),
                'payment_date' => today()->toDateString(),
                'amount' => '345',
                'allocations' => [[
                    'purchase_invoice_id' => $bill->getKey(),
                    'amount' => '345',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = SupplierPayment::query()->firstOrFail();

        Livewire::actingAs($this->admin)
            ->test(EditSupplierPayment::class, ['record' => $payment->getKey()])
            ->callAction('approve');

        $this->assertTrue($payment->refresh()->isApproved());

        $decorated = app(BillOutstanding::class)
            ->decorate(PurchaseInvoice::query())
            ->whereKey($bill->getKey())
            ->firstOrFail();

        $this->assertSame('paid', $decorated->paymentStatus());
    }

    #[Test]
    public function allocating_from_the_view_page_moves_the_advance(): void
    {
        $bill = $this->approvedBill();

        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => today(),
            'amount' => '500.00',
        ]);
        app(SupplierPaymentPoster::class)->approve($payment);

        Livewire::actingAs($this->admin)
            ->test(ViewSupplierPayment::class, ['record' => $payment->getKey()])
            ->callAction('allocate', [
                'purchase_invoice_id' => $bill->getKey(),
                'amount' => '345',
                'date' => today()->toDateString(),
            ])
            ->assertNotified(__('purchases.payments.actions.allocated'));

        $this->assertSame('155.0000', $payment->refresh()->unallocatedAmount());
    }

    // ------------------------------------------------------------------ helpers

    private function approvedBill(): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => today()->subDays(5),
            'due_date' => today()->addDays(30),
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $invoice->getKey(),
            'product_name' => 'توريد بضاعة',
            'expense_account_id' => app(AccountRegistry::class)->get(SystemAccount::CostOfGoodsSold)->getKey(),
            'quantity' => '1',
            'unit_price' => '345.00',
            'is_inclusive' => true,
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
        ]);

        return app(PurchaseInvoicePoster::class)->approve(
            app(PurchaseInvoiceRecalculator::class)->recalculate($invoice->refresh()),
        );
    }

    private function paymentAccount(): Account
    {
        return Account::query()->where('is_payment_account', true)->orderBy('code')->firstOrFail();
    }
}
