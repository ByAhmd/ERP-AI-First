<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\CreditNotePoster;
use App\Services\Sales\CreditNoteRecalculator;
use App\Services\Sales\CustomerReceiptPoster;
use App\Services\Sales\Exceptions\CreditNoteRejected;
use App\Services\Sales\Exceptions\ReceiptRejected;
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
 * Customer receipts.
 *
 * Money allocation is where a ledger goes wrong without ever looking wrong:
 * over-allocating balances, depositing into the receivable account itself
 * balances, paying another customer's invoice balances, and a stale two-term
 * outstanding figure balances. Every test here pins a case where the entry
 * would have been perfectly balanced and perfectly wrong.
 */
final class CustomerReceiptPostingTest extends TestCase
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

    #[Test]
    public function a_fully_allocated_receipt_debits_cash_and_credits_receivable(): void
    {
        $invoice = $this->approvedInvoice('1000');

        $receipt = $this->draftReceipt('1150', [[$invoice, '1150']]);
        $approved = app(CustomerReceiptPoster::class)->approve($receipt);

        $this->assertSame(DocumentStatus::Approved, $approved->status);
        $this->assertTrue($approved->journalEntry->isBalanced());
        $this->assertCount(2, $approved->journalEntry->lines);

        $this->assertSame('1150.0000', $this->lineOnAccount($approved, $this->cashAccount(), 'debit'));
        $this->assertSame('1150.0000', $this->lineOnRole($approved, SystemAccount::AccountsReceivable, 'credit'));

        // And the invoice reads paid.
        $this->assertSame('0.0000', app(InvoiceOutstanding::class)->outstanding($invoice->refresh()));
    }

    #[Test]
    public function the_unallocated_remainder_is_a_customer_advance_not_a_receivable_credit(): void
    {
        // Crediting AR for money no invoice explains would understate the
        // receivable against invoices still showing outstanding — statement
        // and aging disagreeing forever.
        $invoice = $this->approvedInvoice('1000');

        $receipt = $this->draftReceipt('1500', [[$invoice, '1150']]);
        $approved = app(CustomerReceiptPoster::class)->approve($receipt);

        $this->assertCount(3, $approved->journalEntry->lines);
        $this->assertSame('1500.0000', $this->lineOnAccount($approved, $this->cashAccount(), 'debit'));
        $this->assertSame('1150.0000', $this->lineOnRole($approved, SystemAccount::AccountsReceivable, 'credit'));
        $this->assertSame('350.0000', $this->lineOnRole($approved, SystemAccount::CustomerAdvances, 'credit'));
        $this->assertSame('350.0000', $approved->unallocatedAmount());
    }

    #[Test]
    public function a_wholly_on_account_receipt_credits_advances_only(): void
    {
        $receipt = $this->draftReceipt('2000', []);
        $approved = app(CustomerReceiptPoster::class)->approve($receipt);

        $this->assertCount(2, $approved->journalEntry->lines);
        $this->assertSame('2000.0000', $this->lineOnRole($approved, SystemAccount::CustomerAdvances, 'credit'));

        // The balance sheet holds it as a liability, and still balances.
        $balance = app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse('2026-12-31'));
        $this->assertTrue($balance->isBalanced());
    }

    #[Test]
    public function a_draft_receipt_affects_no_outstanding_figure(): void
    {
        // An abandoned draft must not make an invoice look paid — or block the
        // real receipt that follows, inexplicably.
        $invoice = $this->approvedInvoice('1000');
        $this->draftReceipt('1150', [[$invoice, '1150']]);

        $this->assertSame('1150.0000', app(InvoiceOutstanding::class)->outstanding($invoice->refresh()));

        $second = $this->draftReceipt('1150', [[$invoice, '1150']]);
        $approved = app(CustomerReceiptPoster::class)->approve($second);

        $this->assertTrue($approved->isApproved());
    }

    #[Test]
    public function the_second_receipt_sees_what_the_first_already_collected(): void
    {
        // The three-term outstanding: total − credit notes − receipts. Missing
        // the third term over-collects on every second receipt.
        $invoice = $this->approvedInvoice('1000');

        app(CustomerReceiptPoster::class)->approve($this->draftReceipt('800', [[$invoice, '800']]));

        $this->expectException(ReceiptRejected::class);

        app(CustomerReceiptPoster::class)->approve($this->draftReceipt('500', [[$invoice, '500']]));
    }

    #[Test]
    public function a_credit_note_can_no_longer_credit_what_receipts_have_collected(): void
    {
        // The latent bug the design pass found: the credit-note guard used a
        // two-term remainder that ignored receipts, so a fully-paid invoice
        // could be fully credited on top — AR negative for the customer,
        // inside a control account that can never show it.
        $invoice = $this->approvedInvoice('1000');

        app(CustomerReceiptPoster::class)->approve($this->draftReceipt('1150', [[$invoice, '1150']]));

        $this->expectException(CreditNoteRejected::class);

        $this->approvedCreditNoteAgainst($invoice, '1', '1000.00');
    }

    #[Test]
    public function allocating_beyond_the_invoice_after_a_credit_note_is_refused(): void
    {
        $invoice = $this->approvedInvoice('1000');
        $this->approvedCreditNoteAgainst($invoice, '1', '400.00');

        // 1150 gross − 460 credited leaves 690. 700 must be refused.
        $this->expectException(ReceiptRejected::class);

        app(CustomerReceiptPoster::class)->approve($this->draftReceipt('700', [[$invoice, '700']]));
    }

    #[Test]
    public function depositing_into_an_unflagged_account_is_refused(): void
    {
        // Receivable is itself an asset: pointing the receipt at it is a
        // perfect wash entry, so the gate is the payment flag, not the type.
        $invoice = $this->approvedInvoice('1000');
        $receivable = app(AccountRegistry::class)->get(SystemAccount::AccountsReceivable);

        $receipt = $this->draftReceipt('1150', [[$invoice, '1150']]);
        $receipt->forceFill(['deposit_account_id' => $receivable->getKey()])->save();

        $this->expectException(ReceiptRejected::class);

        app(CustomerReceiptPoster::class)->approve($receipt->refresh());
    }

    #[Test]
    public function paying_another_customers_invoice_is_refused(): void
    {
        $invoice = $this->approvedInvoice('1000');
        $other = Contact::create(['contact_name' => 'عميل آخر']);

        $receipt = $this->draftReceipt('1150', [[$invoice, '1150']]);
        $receipt->forceFill(['contact_id' => $other->getKey()])->save();

        $this->expectException(ReceiptRejected::class);

        app(CustomerReceiptPoster::class)->approve($receipt->refresh());
    }

    #[Test]
    public function allocations_beyond_the_receipts_own_amount_are_refused(): void
    {
        $invoice = $this->approvedInvoice('1000');

        $this->expectException(ReceiptRejected::class);

        app(CustomerReceiptPoster::class)->approve(
            $this->draftReceipt('500', [[$invoice, '800']]),
        );
    }

    #[Test]
    public function allocating_an_advance_later_is_its_own_accounting_event(): void
    {
        // The movement gets its own entry at its own date — reopening the
        // receipt's original entry would restate the period the money arrived.
        $receipt = app(CustomerReceiptPoster::class)->approve($this->draftReceipt('2000', []));
        $invoice = $this->approvedInvoice('1000');

        $allocation = app(CustomerReceiptPoster::class)->allocate(
            $receipt,
            $invoice,
            '1150',
            CarbonImmutable::parse('2026-05-10'),
        );

        $this->assertNotNull($allocation->journal_entry_id);
        $this->assertNotSame($receipt->journal_entry_id, $allocation->journal_entry_id);

        $entry = $allocation->journalEntry;
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('2026-05-10', $entry->entry_date->toDateString());

        $this->assertSame('0.0000', app(InvoiceOutstanding::class)->outstanding($invoice->refresh()));
        $this->assertSame('850.0000', $receipt->refresh()->unallocatedAmount());
    }

    #[Test]
    public function releasing_an_allocation_is_the_mirror_event(): void
    {
        $receipt = app(CustomerReceiptPoster::class)->approve($this->draftReceipt('2000', []));
        $invoice = $this->approvedInvoice('1000');

        $allocation = app(CustomerReceiptPoster::class)->allocate(
            $receipt, $invoice, '1150', CarbonImmutable::parse('2026-05-10'),
        );

        app(CustomerReceiptPoster::class)->unallocate($allocation, CarbonImmutable::parse('2026-06-01'));

        $this->assertSame(0, CustomerReceiptAllocation::query()->count());
        $this->assertSame('1150.0000', app(InvoiceOutstanding::class)->outstanding($invoice->refresh()));
        $this->assertSame('2000.0000', $receipt->refresh()->unallocatedAmount());

        // And the books still balance after the round trip.
        $balance = app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse('2026-12-31'));
        $this->assertTrue($balance->isBalanced());
    }

    #[Test]
    public function allocating_more_than_the_remaining_advance_is_refused(): void
    {
        $receipt = app(CustomerReceiptPoster::class)->approve($this->draftReceipt('1000', []));
        $invoiceA = $this->approvedInvoice('700');
        $invoiceB = $this->approvedInvoice('700');

        app(CustomerReceiptPoster::class)->allocate(
            $receipt, $invoiceA, '805', CarbonImmutable::parse('2026-05-10'),
        );

        $this->expectException(ReceiptRejected::class);

        app(CustomerReceiptPoster::class)->allocate(
            $receipt, $invoiceB, '400', CarbonImmutable::parse('2026-05-11'),
        );
    }

    #[Test]
    public function receipts_are_numbered_in_their_own_series(): void
    {
        // The allocator applies defaults only at counter creation — sharing
        // the invoice key would hand out invoice numbers.
        $this->approvedInvoice('100');

        $this->assertSame('RCT-00001', app(CustomerReceiptPoster::class)->nextReference());
        $this->assertSame('RCT-00002', app(CustomerReceiptPoster::class)->nextReference());
    }

    #[Test]
    public function the_invoice_payment_status_derives_from_approved_documents_only(): void
    {
        $invoice = $this->approvedInvoice('1000');

        $status = fn (): string => app(InvoiceOutstanding::class)
            ->decorate(SalesInvoice::query()->whereKey($invoice->getKey()))
            ->firstOrFail()
            ->paymentStatus();

        $this->assertSame('unpaid', $status());

        $receipt = $this->draftReceipt('575', [[$invoice, '575']]);
        $this->assertSame('unpaid', $status());

        app(CustomerReceiptPoster::class)->approve($receipt);
        $this->assertSame('partially_paid', $status());

        app(CustomerReceiptPoster::class)->approve($this->draftReceipt('575', [[$invoice, '575']]));
        $this->assertSame('paid', $status());
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * An approved standard-rated invoice for the given net.
     */
    private function approvedInvoice(string $net): SalesInvoice
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
            'unit_price' => $net,
            'tax_id' => Tax::query()->where('is_default', true)->value('id'),
        ]);

        return app(SalesInvoicePoster::class)->approve(
            app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh()),
        );
    }

    /**
     * @param  list<array{0: SalesInvoice, 1: string}>  $allocations
     */
    private function draftReceipt(string $amount, array $allocations): CustomerReceipt
    {
        $receipt = CustomerReceipt::create([
            'reference' => app(CustomerReceiptPoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'deposit_account_id' => $this->cashAccount()->getKey(),
            'receipt_date' => CarbonImmutable::parse('2026-04-01'),
            'amount' => $amount,
        ]);

        foreach ($allocations as [$invoice, $allocated]) {
            CustomerReceiptAllocation::create([
                'customer_receipt_id' => $receipt->getKey(),
                'sales_invoice_id' => $invoice->getKey(),
                'amount' => $allocated,
            ]);
        }

        return $receipt->refresh();
    }

    private function approvedCreditNoteAgainst(SalesInvoice $invoice, string $qty, string $price): void
    {
        $note = SalesCreditNote::create([
            'reference' => app(CreditNotePoster::class)->nextReference(),
            'contact_id' => $invoice->contact_id,
            'parent_id' => $invoice->getKey(),
            'original_invoice_number' => $invoice->reference,
            'issue_date' => CarbonImmutable::parse('2026-03-20'),
            'due_date' => CarbonImmutable::parse('2026-03-20'),
            'event_date' => CarbonImmutable::parse('2026-03-18'),
            'reason_code' => CreditNoteReason::GoodsReturn,
            'reason_text' => 'إرجاع بضاعة',
        ]);

        SalesCreditNoteItem::create([
            'sales_credit_note_id' => $note->getKey(),
            'sales_invoice_item_id' => $invoice->items()->first()?->getKey(),
            'quantity' => $qty,
            'unit_price' => $price,
        ]);

        app(CreditNotePoster::class)->approve(
            app(CreditNoteRecalculator::class)->recalculate($note->refresh()),
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

    private function lineOnRole(CustomerReceipt $receipt, SystemAccount $role, string $side): string
    {
        return $this->lineOnAccount($receipt, app(AccountRegistry::class)->get($role), $side);
    }

    private function lineOnAccount(CustomerReceipt $receipt, Account $account, string $side): string
    {
        $line = $receipt->journalEntry->lines->firstWhere('account_id', $account->getKey());

        return $line === null ? '0.0000' : (string) $line->{$side};
    }
}
