<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseDebitNoteItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\BillOutstanding;
use App\Services\Purchases\DebitNotePoster;
use App\Services\Purchases\DebitNoteRecalculator;
use App\Services\Purchases\Exceptions\DebitNoteRejected;
use App\Services\Purchases\Exceptions\PaymentRejected;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Purchases\SupplierPaymentPoster;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Supplier payment vouchers.
 *
 * The direction tests are the point of this file. The advance to a supplier
 * is an ASSET on 1170 — flipping only the account roles or only the sides
 * of the sales mirror each produce balanced garbage: 2180 polluted with
 * supplier debits, or an advance that doubles while the payable grows. So
 * these tests assert which account moved, which way, and that 2180 never
 * moved at all.
 */
final class SupplierPaymentPostingTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeAccountingCompany(2026);

        app(TaxTemplate::class)->applyTo($this->company);
        app(CatalogueTemplate::class)->applyTo($this->company);

        $this->supplier = Contact::create([
            'contact_name' => 'شركة التوريدات الأولى',
            'type' => ContactType::Supplier,
        ]);
    }

    /** The founding direction test: 1170 DEBITED, 2180 untouched. */
    #[Test]
    public function an_unallocated_remainder_debits_the_supplier_advance_asset(): void
    {
        $bill = $this->approvedBill('345.00');

        $payment = $this->approvedPayment('500.00', [[$bill, '345.0000']]);

        $entry = $payment->journalEntry;
        $this->assertTrue($entry->isBalanced());

        // Allocated settles the payable; the remainder is OUR money with
        // the supplier — an asset debit on 1170.
        $this->assertSame('345.0000', $this->lineOn($payment, $this->account(SystemAccount::AccountsPayable)->getKey(), 'debit'));
        $this->assertSame('155.0000', $this->lineOn($payment, $this->account(SystemAccount::SupplierAdvances)->getKey(), 'debit'));
        $this->assertSame('500.0000', $this->lineOn($payment, $this->paymentAccount()->getKey(), 'credit'));

        // The customer advances liability must show ZERO movement.
        $this->assertNull($this->lineOn($payment, $this->account(SystemAccount::CustomerAdvances)->getKey(), 'debit'));
        $this->assertNull($this->lineOn($payment, $this->account(SystemAccount::CustomerAdvances)->getKey(), 'credit'));
    }

    #[Test]
    public function a_fully_allocated_voucher_carries_no_advance_line(): void
    {
        $bill = $this->approvedBill('345.00');

        $payment = $this->approvedPayment('345.00', [[$bill, '345.0000']]);

        $this->assertNull($this->lineOn($payment, $this->account(SystemAccount::SupplierAdvances)->getKey(), 'debit'));
        $this->assertSame('paid', $this->decorated($bill)->paymentStatus());
    }

    #[Test]
    public function a_wholly_unallocated_voucher_is_pure_advance(): void
    {
        $payment = $this->approvedPayment('1000.00', []);

        $this->assertSame('1000.0000', $this->lineOn($payment, $this->account(SystemAccount::SupplierAdvances)->getKey(), 'debit'));
        $this->assertNull($this->lineOn($payment, $this->account(SystemAccount::AccountsPayable)->getKey(), 'debit'));
    }

    /** Later allocation: the advance asset is CREDITED — killed — not debited. */
    #[Test]
    public function allocating_an_advance_later_credits_the_advance_and_debits_the_payable(): void
    {
        $payment = $this->approvedPayment('1000.00', []);
        $bill = $this->approvedBill('345.00');

        $allocation = app(SupplierPaymentPoster::class)->allocate(
            $payment,
            $bill,
            '345.0000',
            today()->toImmutable(),
        );

        $entry = $allocation->journalEntry;
        $this->assertNotNull($entry);

        $ap = $this->account(SystemAccount::AccountsPayable)->getKey();
        $advances = $this->account(SystemAccount::SupplierAdvances)->getKey();

        $this->assertSame('345.0000', (string) $entry->lines()->where('account_id', $ap)->first()?->debit);
        $this->assertSame('345.0000', (string) $entry->lines()->where('account_id', $advances)->first()?->credit);

        $this->assertSame('paid', $this->decorated($bill)->paymentStatus());
        $this->assertSame('655.0000', $payment->refresh()->unallocatedAmount());
    }

    #[Test]
    public function unallocating_mirrors_the_movement_and_deletes_the_row(): void
    {
        $payment = $this->approvedPayment('345.00', []);
        $bill = $this->approvedBill('345.00');

        $allocation = app(SupplierPaymentPoster::class)->allocate(
            $payment, $bill, '345.0000', today()->toImmutable(),
        );

        app(SupplierPaymentPoster::class)->unallocate($allocation, today()->toImmutable());

        $this->assertSame(0, SupplierPaymentAllocation::query()->count());
        $this->assertSame('unpaid', $this->decorated($bill)->paymentStatus());
        $this->assertSame('345.0000', $payment->refresh()->unallocatedAmount());
    }

    #[Test]
    public function allocation_beyond_the_unallocated_remainder_is_refused(): void
    {
        $bill = $this->approvedBill('345.00');
        $payment = $this->approvedPayment('100.00', []);

        $this->expectException(PaymentRejected::class);

        app(SupplierPaymentPoster::class)->allocate(
            $payment, $bill, '200.0000', today()->toImmutable(),
        );
    }

    #[Test]
    public function allocation_to_another_suppliers_bill_is_refused(): void
    {
        $other = Contact::create(['contact_name' => 'مورد آخر', 'type' => ContactType::Supplier]);
        $bill = $this->approvedBill('345.00');

        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $other->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => today(),
            'amount' => '500.00',
        ]);
        app(SupplierPaymentPoster::class)->approve($payment);

        $this->expectException(PaymentRejected::class);

        app(SupplierPaymentPoster::class)->allocate(
            $payment, $bill, '100.0000', today()->toImmutable(),
        );
    }

    #[Test]
    public function a_voucher_for_a_customer_typed_contact_is_refused(): void
    {
        $customer = Contact::create(['contact_name' => 'عميل', 'type' => ContactType::Customer]);

        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $customer->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => today(),
            'amount' => '100.00',
        ]);

        try {
            app(SupplierPaymentPoster::class)->approve($payment);
            $this->fail('A voucher paying a customer should refuse.');
        } catch (PaymentRejected) {
            $this->assertSame(0, JournalEntry::query()->count());
        }
    }

    #[Test]
    public function a_non_payment_account_is_refused(): void
    {
        // Accounts payable itself: an asset-typed check would not catch
        // this; the flag does.
        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'payment_account_id' => $this->account(SystemAccount::AccountsPayable)->getKey(),
            'payment_date' => today(),
            'amount' => '100.00',
        ]);

        $this->expectException(PaymentRejected::class);

        app(SupplierPaymentPoster::class)->approve($payment);
    }

    #[Test]
    public function a_payment_dated_before_the_bill_approves_unallocated_but_refuses_the_allocation(): void
    {
        $bill = $this->approvedBill('345.00', issueDate: today());

        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => today()->subDays(10),
            'amount' => '345.00',
        ]);

        SupplierPaymentAllocation::create([
            'supplier_payment_id' => $payment->getKey(),
            'purchase_invoice_id' => $bill->getKey(),
            'amount' => '345.0000',
        ]);

        try {
            app(SupplierPaymentPoster::class)->approve($payment);
            $this->fail('An earlier-dated allocation should refuse.');
        } catch (PaymentRejected) {
            // Money paid before the bill existed is an advance, not an
            // error — dropped allocation, the voucher approves unallocated.
            $payment->allocations()->delete();
            $approved = app(SupplierPaymentPoster::class)->approve($payment->refresh());

            $this->assertSame('345.0000', $this->lineOn($approved, $this->account(SystemAccount::SupplierAdvances)->getKey(), 'debit'));
        }
    }

    /** The re-lost three-term lesson, pinned from the payments side. */
    #[Test]
    public function a_fully_paid_bill_can_no_longer_be_fully_debited(): void
    {
        $bill = $this->approvedBill('345.00');

        $this->approvedPayment('345.00', [[$bill, '345.0000']]);

        $note = $this->draftNote($bill, '3', '115.00');

        try {
            app(DebitNotePoster::class)->approve($note);
            $this->fail('Debiting what payments already collected should refuse.');
        } catch (DebitNoteRejected) {
            $this->assertSame('0.0000', app(BillOutstanding::class)->outstanding($bill->refresh()));
        }
    }

    #[Test]
    public function vouchers_number_from_their_own_series(): void
    {
        $this->assertSame('PYT-00001', app(SupplierPaymentPoster::class)->nextReference());
        $this->assertSame('BIL-00001', app(PurchaseInvoicePoster::class)->nextReference());
    }

    #[Test]
    public function a_draft_voucher_touches_nothing(): void
    {
        SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => today(),
            'amount' => '100.00',
        ]);

        $this->assertSame(0, JournalEntry::query()->count());
    }

    // ------------------------------------------------------------------ helpers

    private function approvedBill(string $grossTotal, mixed $issueDate = null): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'issue_date' => $issueDate ?? today()->subDays(10),
            'due_date' => today()->addDays(30),
        ]);

        PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $invoice->getKey(),
            'product_name' => 'توريد بضاعة',
            'expense_account_id' => $this->account(SystemAccount::CostOfGoodsSold)->getKey(),
            'quantity' => '1',
            'unit_price' => $grossTotal,
            'is_inclusive' => true,
            'tax_id' => Tax::query()->where('category', TaxCategory::Standard)->value('id'),
        ]);

        return app(PurchaseInvoicePoster::class)->approve(
            app(PurchaseInvoiceRecalculator::class)->recalculate($invoice->refresh()),
        );
    }

    /**
     * @param  list<array{0: PurchaseInvoice, 1: string}>  $allocations
     */
    private function approvedPayment(string $amount, array $allocations): SupplierPayment
    {
        $payment = SupplierPayment::create([
            'reference' => app(SupplierPaymentPoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'payment_account_id' => $this->paymentAccount()->getKey(),
            'payment_date' => today(),
            'amount' => $amount,
        ]);

        foreach ($allocations as [$invoice, $allocated]) {
            SupplierPaymentAllocation::create([
                'supplier_payment_id' => $payment->getKey(),
                'purchase_invoice_id' => $invoice->getKey(),
                'amount' => $allocated,
            ]);
        }

        return app(SupplierPaymentPoster::class)->approve($payment->refresh());
    }

    private function draftNote(PurchaseInvoice $bill, string $quantity, string $unitPrice): PurchaseDebitNote
    {
        $note = PurchaseDebitNote::create([
            'reference' => app(DebitNotePoster::class)->nextReference(),
            'contact_id' => $bill->contact_id,
            'parent_id' => $bill->getKey(),
            'original_invoice_number' => 'placeholder',
            'issue_date' => today(),
        ]);

        PurchaseDebitNoteItem::create([
            'purchase_debit_note_id' => $note->getKey(),
            'purchase_invoice_item_id' => $bill->items()->firstOrFail()->getKey(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'is_inclusive' => true,
        ]);

        return app(DebitNoteRecalculator::class)->recalculate($note->refresh());
    }

    private function decorated(PurchaseInvoice $bill): PurchaseInvoice
    {
        return app(BillOutstanding::class)
            ->decorate(PurchaseInvoice::query())
            ->whereKey($bill->getKey())
            ->firstOrFail();
    }

    private function paymentAccount(): Account
    {
        return Account::query()->where('is_payment_account', true)->orderBy('code')->firstOrFail();
    }

    private function account(SystemAccount $role): Account
    {
        return app(AccountRegistry::class)->get($role);
    }

    private function lineOn(SupplierPayment $payment, string $accountId, string $side): ?string
    {
        $line = $payment->journalEntry?->lines()
            ->where('account_id', $accountId)
            ->first();

        if ($line === null) {
            return null;
        }

        $amount = (string) $line->{$side};

        return bccomp($amount, '0', 4) === 0 ? null : $amount;
    }
}
