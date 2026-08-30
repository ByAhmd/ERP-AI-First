<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Enums\SystemAccount;
use App\Filament\Resources\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\EditPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\ListPurchaseInvoices;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\PurchaseInvoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Accounting\AccountRegistry;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The purchase invoice screens, driven as a person drives them.
 *
 * The one assertion here that exists nowhere else in the suite: choosing a
 * product on a bill line copies its BUYING price, and a product with no
 * buying price leaves the field alone rather than borrowing the selling
 * price — the silently-overstated-expense trap.
 */
final class PurchaseInvoicePanelTest extends TestCase
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
            ->test(ListPurchaseInvoices::class)
            ->assertOk();
    }

    #[Test]
    public function the_create_form_opens_with_a_reference_in_the_bill_series(): void
    {
        $component = Livewire::actingAs($this->admin)->test(CreatePurchaseInvoice::class);

        $component->assertOk();
        $component->assertFormSet(['reference' => 'BIL-00001']);
        $component->assertSee(__('purchases.invoices.fields.supplier_invoice_number'));
        $component->assertSee(__('purchases.invoices.items.expense_account'));
    }

    #[Test]
    public function a_bill_typed_into_the_screen_saves_as_a_draft_with_resolved_totals_and_stamped_kind(): void
    {
        $invoice = $this->draftThroughTheScreen();

        $this->assertSame('standard', $invoice->kind->value);
        $this->assertTrue($invoice->isDraft());
        $this->assertSame('300.0000', (string) $invoice->subtotal_net);
        $this->assertSame('45.0000', (string) $invoice->tax_total);
        $this->assertSame('345.0000', (string) $invoice->total);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    #[Test]
    public function approving_from_the_screen_posts_it(): void
    {
        $invoice = $this->draftThroughTheScreen();

        Livewire::actingAs($this->admin)
            ->test(EditPurchaseInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('approve');

        $invoice->refresh();

        $this->assertTrue($invoice->isApproved());
        $this->assertNotNull($invoice->journal_entry_id);
    }

    #[Test]
    public function an_approved_bill_cannot_be_opened_for_editing(): void
    {
        $invoice = $this->draftThroughTheScreen();

        Livewire::actingAs($this->admin)
            ->test(EditPurchaseInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('approve');

        Livewire::actingAs($this->admin)
            ->test(EditPurchaseInvoice::class, ['record' => $invoice->getKey()])
            ->assertRedirect();
    }

    #[Test]
    public function choosing_a_product_copies_its_buying_price_and_expense_account(): void
    {
        $stationery = Account::query()->where('is_postable', true)
            ->where('code', 'like', '5%')->where('code', '!=', '5100')->firstOrFail();

        $product = $this->product();
        $product->forceFill([
            'buying_price' => '70.00',
            'expense_account_id' => $stationery->getKey(),
        ])->save();

        Livewire::actingAs($this->admin)
            ->test(CreatePurchaseInvoice::class)
            ->fillForm($this->formData([['quantity' => 1, 'unit_price' => 0]]))
            ->set('data.items.0.product_id', $product->getKey())
            ->assertFormSet(function (array $state) use ($stationery): bool {
                $line = $state['items'][array_key_first($state['items'])];

                // The BUYING price — 70, never the 450 selling price.
                $priceOk = (float) $line['unit_price'] === 70.0;

                return $priceOk && $line['expense_account_id'] === $stationery->getKey();
            });
    }

    #[Test]
    public function a_product_without_a_buying_price_never_borrows_the_selling_price(): void
    {
        $product = $this->product();
        $product->forceFill(['buying_price' => null, 'selling_price' => '450'])->save();

        Livewire::actingAs($this->admin)
            ->test(CreatePurchaseInvoice::class)
            ->fillForm($this->formData([['quantity' => 1, 'unit_price' => 0]]))
            ->set('data.items.0.product_id', $product->getKey())
            ->assertFormSet(function (array $state): bool {
                $line = $state['items'][array_key_first($state['items'])];

                return (float) $line['unit_price'] === 0.0;
            });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formData(array $lines): array
    {
        $standardTax = Tax::query()->where('is_default', true)->value('id');
        $cogs = app(AccountRegistry::class)->get(SystemAccount::CostOfGoodsSold);
        $product = $this->product();

        return [
            'reference' => 'BIL-TEST-1',
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'items' => array_map(static fn (array $line): array => [
                'product_id' => $product->getKey(),
                'product_name' => $product->name,
                'product_description' => null,
                'expense_account_id' => $cogs->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'discount_value' => 0,
                'discount_type' => 'percentage',
                'tax_id' => $standardTax,
            ], $lines),
        ];
    }

    private function draftThroughTheScreen(): PurchaseInvoice
    {
        Livewire::actingAs($this->admin)
            ->test(CreatePurchaseInvoice::class)
            ->fillForm($this->formData([['quantity' => 3, 'unit_price' => 115, 'is_inclusive' => true]]))
            ->call('create')
            ->assertHasNoFormErrors();

        return PurchaseInvoice::query()->firstOrFail();
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'is_sold' => true,
            'selling_price' => '450',
            'buying_price' => '70',
        ]);
    }
}
