<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Enums\PurchaseInvoiceKind;
use App\Filament\Resources\PurchaseInvoices\Pages\ListPurchaseInvoices;
use App\Filament\Resources\SimplePurchaseInvoices\Pages\CreateSimplePurchaseInvoice;
use App\Filament\Resources\SimplePurchaseInvoices\Pages\EditSimplePurchaseInvoice;
use App\Filament\Resources\SimplePurchaseInvoices\Pages\ListSimplePurchaseInvoices;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\Purchases\BillOutstanding;
use App\Services\Purchases\Exceptions\PurchaseInvoiceRuleViolation;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Simple bills — the one-table-two-screens invariants.
 *
 * The design bets on a shared table so a simple bill appears in every
 * payable query without a UNION; these tests pin both halves of the bet:
 * the screens stay separated, the money does not.
 */
final class SimplePurchaseInvoiceTest extends TestCase
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
    public function the_create_screen_stamps_the_kind_and_numbers_from_the_sb_series(): void
    {
        $rent = $this->rentAccount();

        $component = Livewire::actingAs($this->admin)->test(CreateSimplePurchaseInvoice::class);

        $component->assertFormSet(['reference' => 'SB-00001']);

        $component->fillForm([
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => today()->toDateString(),
            'items' => [[
                'expense_account_id' => $rent->getKey(),
                'product_description' => 'إيجار المستودع — أغسطس',
                'unit_price' => '5000',
                'quantity' => 1,
                'tax_id' => Tax::query()->where('is_default', true)->value('id'),
            ]],
        ])
            ->call('create')
            ->assertHasNoFormErrors();

        $bill = PurchaseInvoice::query()->firstOrFail();

        $this->assertSame(PurchaseInvoiceKind::Simple, $bill->kind);
        $this->assertSame('SB-00001', $bill->reference);
        // The item hook names the line from its statement.
        $this->assertSame('إيجار المستودع — أغسطس', $bill->items()->firstOrFail()->product_name);
    }

    #[Test]
    public function the_two_series_count_independently(): void
    {
        $this->assertSame('SB-00001', app(PurchaseInvoicePoster::class)->nextSimpleReference());
        $this->assertSame('BIL-00001', app(PurchaseInvoicePoster::class)->nextReference());
        $this->assertSame('SB-00002', app(PurchaseInvoicePoster::class)->nextSimpleReference());
    }

    #[Test]
    public function a_simple_bill_approves_without_a_due_date_and_posts_per_account(): void
    {
        $rent = $this->rentAccount();

        $bill = $this->draftSimpleBill($rent, '5000.00');

        $approved = app(PurchaseInvoicePoster::class)->approve($bill);

        $this->assertTrue($approved->isApproved());
        $this->assertNull($approved->due_date);

        $line = $approved->journalEntry?->lines()->where('account_id', $rent->getKey())->first();

        $this->assertSame('5000.0000', (string) $line?->debit);
    }

    #[Test]
    public function a_standard_bill_still_requires_its_due_date(): void
    {
        // The kind gate, not a blanket relaxation: the same poster refuses a
        // standard bill without one.
        $bill = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'kind' => PurchaseInvoiceKind::Standard,
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => today(),
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $bill->getKey(),
            'product_description' => 'بند',
            'expense_account_id' => $this->rentAccount()->getKey(),
            'quantity' => '1',
            'unit_price' => '100.00',
        ]);

        $recalculated = app(PurchaseInvoiceRecalculator::class)->recalculate($bill->refresh());

        $this->expectException(PurchaseInvoiceRuleViolation::class);

        app(PurchaseInvoicePoster::class)->approve($recalculated);
    }

    #[Test]
    public function a_simple_bill_counts_in_the_shared_outstanding(): void
    {
        $bill = app(PurchaseInvoicePoster::class)->approve(
            $this->draftSimpleBill($this->rentAccount(), '1000.00'),
        );

        // The whole point of one table: no UNION, no forgotten filter — the
        // payable queries see the simple bill natively.
        $this->assertSame('1000.0000', app(BillOutstanding::class)->outstanding($bill));
    }

    #[Test]
    public function each_screen_shows_only_its_own_kind(): void
    {
        $simple = app(PurchaseInvoicePoster::class)->approve(
            $this->draftSimpleBill($this->rentAccount(), '1000.00'),
        );

        $standard = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'kind' => PurchaseInvoiceKind::Standard,
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => today(),
            'due_date' => today(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListSimplePurchaseInvoices::class)
            ->assertCanSeeTableRecords([$simple])
            ->assertCanNotSeeTableRecords([$standard]);

        Livewire::actingAs($this->admin)
            ->test(ListPurchaseInvoices::class)
            ->assertCanSeeTableRecords([$standard])
            ->assertCanNotSeeTableRecords([$simple]);
    }

    #[Test]
    public function a_standard_bill_cannot_be_reached_through_the_simple_edit_route(): void
    {
        $standard = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'kind' => PurchaseInvoiceKind::Standard,
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => today(),
            'due_date' => today(),
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->admin)
            ->test(EditSimplePurchaseInvoice::class, ['record' => $standard->getKey()]);
    }

    // ------------------------------------------------------------------ helpers

    private function draftSimpleBill(Account $account, string $value): PurchaseInvoice
    {
        $bill = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextSimpleReference(),
            'kind' => PurchaseInvoiceKind::Simple,
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => today(),
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $bill->getKey(),
            'product_description' => 'مصروف',
            'expense_account_id' => $account->getKey(),
            'quantity' => '1',
            'unit_price' => $value,
            'is_inclusive' => true,
            'tax_id' => null,
        ]);

        return app(PurchaseInvoiceRecalculator::class)->recalculate($bill->refresh());
    }

    private function rentAccount(): Account
    {
        return Account::query()->where('is_postable', true)
            ->where('code', 'like', '52%')->firstOrFail();
    }
}
