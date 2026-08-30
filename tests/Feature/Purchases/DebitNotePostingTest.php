<?php

declare(strict_types=1);

namespace Tests\Feature\Purchases;

use App\Enums\ContactType;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\PurchaseDebitNote;
use App\Models\PurchaseDebitNoteItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Purchases\BillOutstanding;
use App\Services\Purchases\DebitNotePoster;
use App\Services\Purchases\DebitNoteRecalculator;
use App\Services\Purchases\Exceptions\DebitNoteRejected;
use App\Services\Purchases\PurchaseInvoicePoster;
use App\Services\Purchases\PurchaseInvoiceRecalculator;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\TaxTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Purchase debit notes.
 *
 * A debit note reaches the ledger perfectly balanced no matter how wrong it
 * is — payable is one control account with no supplier on the line, so
 * over-debiting, debiting the wrong supplier, and debiting at today's rate
 * all balance. Every guard has to hold on its own.
 */
final class DebitNotePostingTest extends TestCase
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

    /** The mirror identity: AP debited, expense and VAT INPUT credited. */
    #[Test]
    public function debiting_a_bill_in_full_reverses_every_balance(): void
    {
        $bill = $this->approvedBill([['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]);

        $note = $this->approvedNote($bill, [['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]);

        $this->assertSame(DocumentStatus::Approved, $note->status);
        $this->assertTrue($note->journalEntry->isBalanced());

        $this->assertSame('345.0000', $this->lineOn($note, $this->account(SystemAccount::AccountsPayable)->getKey(), 'debit'));
        $this->assertSame('300.0000', $this->lineOn($note, $this->cogs()->getKey(), 'credit'));
        $this->assertSame('45.0000', $this->lineOn($note, $this->account(SystemAccount::VatInputRecoverable)->getKey(), 'credit'));

        // VAT output untouched, as on the bill itself.
        $this->assertNull($this->lineOn($note, $this->account(SystemAccount::VatOutputPayable)->getKey(), 'credit'));

        $this->assertSame('0.0000', app(BillOutstanding::class)->outstanding($bill->refresh()));
    }

    #[Test]
    public function the_note_hands_back_the_rate_the_bill_was_keyed_at_not_todays(): void
    {
        $bill = $this->approvedBill([['quantity' => '2', 'unit_price' => '100.00']]);

        // The law changes after billing — the correction must return 15%.
        $tax = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();
        $tax->forceFill(['rate' => '5.00'])->save();

        $note = $this->approvedNote($bill, [['quantity' => '1', 'unit_price' => '100.00']]);

        $this->assertSame('15.0000', (string) $note->items()->firstOrFail()->tax_rate);
        $this->assertSame('15.0000', (string) $note->tax_total);
    }

    #[Test]
    public function a_note_cannot_exceed_what_remains_of_the_bill(): void
    {
        $bill = $this->approvedBill([['quantity' => '1', 'unit_price' => '100.00']]);

        // The anchored line inherits the bill's 15% whatever tax the form
        // picked — the correction returns what was billed. 60 net → 69 gross.
        $this->approvedNote($bill, [['quantity' => '1', 'unit_price' => '60.00']]);

        try {
            $this->approvedNote($bill, [['quantity' => '1', 'unit_price' => '80.00']]);
            $this->fail('Over-debiting should refuse.');
        } catch (DebitNoteRejected) {
            // 115 gross − 69 = 46 remaining; the 92 note exceeds it.
            $this->assertSame('46.0000', app(BillOutstanding::class)->outstanding($bill->refresh()));
        }
    }

    #[Test]
    public function the_outstanding_figure_is_three_term_even_before_payments_exist(): void
    {
        $bill = $this->approvedBill([['quantity' => '1', 'unit_price' => '100.00', 'category' => TaxCategory::ZeroRated]]);

        // The payments term is wired and sums zero — the join must not error
        // and must not change the answer while the table is empty.
        $outstanding = app(BillOutstanding::class);

        $this->assertSame('100.0000', $outstanding->outstanding($bill));
        $this->assertSame('0.0000', $outstanding->paidOn($bill));
        $this->assertSame('0.0000', $outstanding->debitedOn($bill));
    }

    #[Test]
    public function a_note_against_another_suppliers_bill_is_refused(): void
    {
        $bill = $this->approvedBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $other = Contact::create([
            'contact_name' => 'مورد آخر',
            'type' => ContactType::Supplier,
        ]);

        $note = $this->draftNote($bill, [['quantity' => '1', 'unit_price' => '50.00']]);
        $note->forceFill(['contact_id' => $other->getKey()])->save();

        $this->expectException(DebitNoteRejected::class);

        app(DebitNotePoster::class)->approve($note->refresh());
    }

    #[Test]
    public function only_an_approved_bill_can_be_corrected(): void
    {
        $draft = $this->draftBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $note = $this->draftNote($draft, [['quantity' => '1', 'unit_price' => '50.00']]);

        $this->expectException(DebitNoteRejected::class);

        app(DebitNotePoster::class)->approve($note);
    }

    #[Test]
    public function a_note_dated_before_its_bill_is_refused(): void
    {
        $bill = $this->approvedBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $note = $this->draftNote($bill, [['quantity' => '1', 'unit_price' => '50.00']]);
        $note->forceFill(['issue_date' => $bill->issue_date->subDay()])->save();

        $this->expectException(DebitNoteRejected::class);

        app(DebitNotePoster::class)->approve($note->refresh());
    }

    #[Test]
    public function a_standalone_note_approves_without_a_parent(): void
    {
        // A bill from a predecessor system: nothing to anchor to, the
        // external reference carries the identity.
        $note = PurchaseDebitNote::create([
            'reference' => app(DebitNotePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'original_invoice_number' => 'OLD-SYS-774',
            'issue_date' => today(),
        ]);

        PurchaseDebitNoteItem::create([
            'purchase_debit_note_id' => $note->getKey(),
            'product_name' => 'مرتجع بضاعة',
            'expense_account_id' => $this->cogs()->getKey(),
            'quantity' => '1',
            'unit_price' => '200.00',
            'tax_id' => Tax::query()->where('category', TaxCategory::Standard)->value('id'),
        ]);

        $approved = app(DebitNotePoster::class)->approve(
            app(DebitNoteRecalculator::class)->recalculate($note->refresh()),
        );

        $this->assertTrue($approved->isApproved());
        $this->assertStringContainsString('OLD-SYS-774', (string) $approved->journalEntry?->description);
    }

    #[Test]
    public function the_narration_names_the_suppliers_invoice_not_ours(): void
    {
        $bill = $this->approvedBill(
            [['quantity' => '1', 'unit_price' => '100.00']],
            supplierNumber: 'SUP-INV-909',
        );

        $note = $this->approvedNote($bill, [['quantity' => '1', 'unit_price' => '50.00']]);

        $description = (string) $note->journalEntry?->description;

        $this->assertStringContainsString('SUP-INV-909', $description);
        $this->assertStringNotContainsString('BIL-', $description);
    }

    #[Test]
    public function the_anchored_line_relieves_the_account_the_cost_landed_in(): void
    {
        $rent = Account::query()->where('is_postable', true)
            ->where('code', 'like', '52%')->firstOrFail();

        $bill = $this->approvedBill([[
            'quantity' => '1', 'unit_price' => '100.00', 'expense_account_id' => $rent->getKey(),
        ]]);

        // The product's own default is CoGS; the anchored copy must win.
        $note = $this->approvedNote($bill, [['quantity' => '1', 'unit_price' => '100.00']]);

        $this->assertSame('100.0000', $this->lineOn($note, $rent->getKey(), 'credit'));
        $this->assertNull($this->lineOn($note, $this->cogs()->getKey(), 'credit'));
    }

    #[Test]
    public function a_zero_amount_note_is_refused(): void
    {
        $bill = $this->approvedBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $note = $this->draftNote($bill, [['quantity' => '0', 'unit_price' => '0']]);

        $this->expectException(DebitNoteRejected::class);

        app(DebitNotePoster::class)->approve($note);
    }

    #[Test]
    public function notes_number_from_their_own_series(): void
    {
        $this->assertSame('DBN-00001', app(DebitNotePoster::class)->nextReference());
        $this->assertSame('BIL-00001', app(PurchaseInvoicePoster::class)->nextReference());
    }

    #[Test]
    public function a_draft_note_touches_nothing(): void
    {
        $bill = $this->approvedBill([['quantity' => '1', 'unit_price' => '100.00']]);

        $before = JournalEntry::query()->count();

        $this->draftNote($bill, [['quantity' => '1', 'unit_price' => '50.00']]);

        $this->assertSame($before, JournalEntry::query()->count());
        $this->assertSame('0.0000', app(BillOutstanding::class)->debitedOn($bill));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedBill(array $lines, ?string $supplierNumber = null): PurchaseInvoice
    {
        return app(PurchaseInvoicePoster::class)->approve(
            $this->draftBill($lines, $supplierNumber),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftBill(array $lines, ?string $supplierNumber = null): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::create([
            'reference' => app(PurchaseInvoicePoster::class)->nextReference(),
            'contact_id' => $this->supplier->getKey(),
            'supplier_invoice_number' => $supplierNumber,
            'issue_date' => today()->subDays(5),
            'due_date' => today()->addDays(30),
        ]);

        foreach ($lines as $line) {
            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $invoice->getKey(),
                'product_id' => $this->product()->getKey(),
                'product_name' => 'ورق تصوير',
                'expense_account_id' => $line['expense_account_id'] ?? $this->cogs()->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'tax_id' => Tax::query()->where('category', $line['category'] ?? TaxCategory::Standard)->value('id'),
            ]);
        }

        return app(PurchaseInvoiceRecalculator::class)->recalculate($invoice->refresh());
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedNote(PurchaseInvoice $bill, array $lines): PurchaseDebitNote
    {
        return app(DebitNotePoster::class)->approve($this->draftNote($bill, $lines));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftNote(PurchaseInvoice $bill, array $lines): PurchaseDebitNote
    {
        $note = PurchaseDebitNote::create([
            'reference' => app(DebitNotePoster::class)->nextReference(),
            'contact_id' => $bill->contact_id,
            'parent_id' => $bill->getKey(),
            'original_invoice_number' => 'placeholder',
            'issue_date' => today(),
        ]);

        foreach ($lines as $line) {
            PurchaseDebitNoteItem::create([
                'purchase_debit_note_id' => $note->getKey(),
                'product_id' => $this->product()->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'tax_id' => Tax::query()->where('category', $line['category'] ?? TaxCategory::Standard)->value('id'),
            ]);
        }

        return app(DebitNoteRecalculator::class)->recalculate($note->refresh());
    }

    private function product(): Product
    {
        return Product::query()->first() ?? Product::create([
            'name' => 'ورق تصوير',
            'name_en' => 'Copy Paper',
            'unit_type_id' => ProductUnitType::query()->value('id'),
            'is_purchased' => true,
            'buying_price' => '100',
        ]);
    }

    private function cogs(): Account
    {
        return $this->account(SystemAccount::CostOfGoodsSold);
    }

    private function account(SystemAccount $role): Account
    {
        return app(AccountRegistry::class)->get($role);
    }

    private function lineOn(PurchaseDebitNote $note, string $accountId, string $side): ?string
    {
        $line = $note->journalEntry?->lines()
            ->where('account_id', $accountId)
            ->first();

        if ($line === null) {
            return null;
        }

        $amount = (string) $line->{$side};

        return bccomp($amount, '0', 4) === 0 ? null : $amount;
    }
}
