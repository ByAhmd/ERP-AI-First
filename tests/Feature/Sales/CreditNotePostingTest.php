<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Enums\SystemAccount;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductUnitType;
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Tax;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\IncomeStatement;
use App\Services\Sales\CatalogueTemplate;
use App\Services\Sales\CreditNotePoster;
use App\Services\Sales\CreditNoteRecalculator;
use App\Services\Sales\Exceptions\CreditNoteRejected;
use App\Services\Sales\SalesInvoicePoster;
use App\Services\Sales\SalesInvoiceRecalculator;
use App\Services\Sales\TaxTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Sales credit notes.
 *
 * A credit note reaches the ledger as a perfectly balanced entry no matter how
 * wrong it is. Over-crediting balances. Crediting the wrong customer balances —
 * receivable is one control account with no contact on the line, so the ledger
 * cannot even see the difference. Crediting at a rate that has since changed
 * balances. There is no accounting backstop here, so every guard below has to
 * hold on its own, and each of these tests exists because the corresponding
 * mistake would otherwise ship silently.
 */
final class CreditNotePostingTest extends TestCase
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
    public function crediting_an_invoice_in_full_returns_every_balance_to_zero(): void
    {
        $invoice = $this->approvedInvoice([['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]);

        $note = $this->approvedCreditNote($invoice, [['quantity' => '3', 'unit_price' => '115.00', 'is_inclusive' => true]]);

        $this->assertSame(DocumentStatus::Approved, $note->status);
        $this->assertTrue($note->journalEntry->isBalanced());

        // The mirror image of the invoice: revenue and VAT debited, receivable
        // credited.
        $this->assertSame('300.0000', $this->lineOn($note, SystemAccount::SalesRevenue, 'debit'));
        $this->assertSame('45.0000', $this->lineOn($note, SystemAccount::VatOutputPayable, 'debit'));
        $this->assertSame('345.0000', $this->lineOn($note, SystemAccount::AccountsReceivable, 'credit'));

        $balance = app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse('2026-12-31'));
        $income = app(IncomeStatement::class)->build(
            from: CarbonImmutable::parse('2026-01-01'),
            to: CarbonImmutable::parse('2026-12-31'),
        );

        $this->assertTrue($balance->isBalanced());
        $this->assertSame('0.0000', $this->sectionTotal($balance, 'assets'));
        $this->assertSame('0.0000', $this->sectionTotal($balance, 'liabilities'));
        $this->assertSame('0.0000', $this->sectionTotal($income, 'revenue'));
    }

    #[Test]
    public function it_posts_its_own_entry_rather_than_reversing_the_invoices(): void
    {
        // Reversal cannot express a partial credit, permits only one per
        // invoice, and would claim the invoice as its source document.
        $invoice = $this->approvedInvoice([['quantity' => '1', 'unit_price' => '100.00']]);
        $note = $this->approvedCreditNote($invoice, [['quantity' => '1', 'unit_price' => '100.00']]);

        $entry = $note->journalEntry;

        $this->assertNull($entry->reverses_id);
        $this->assertSame(SalesCreditNote::class, $entry->source_type);
        $this->assertSame($note->getKey(), $entry->source_id);
        // Numbered in the primary series, not the corrections one.
        $this->assertStringStartsWith('JE-', $entry->number);
    }

    #[Test]
    public function two_partial_credit_notes_can_be_raised_against_one_invoice(): void
    {
        // The case reversal makes impossible: the ledger's unique index on
        // reverses_id permits exactly one.
        $invoice = $this->approvedInvoice([['quantity' => '10', 'unit_price' => '100.00']]);

        $this->approvedCreditNote($invoice, [['quantity' => '4', 'unit_price' => '100.00']]);
        $second = $this->approvedCreditNote($invoice, [['quantity' => '6', 'unit_price' => '100.00']]);

        $this->assertSame(DocumentStatus::Approved, $second->status);
        $this->assertSame('0.0000', app(CreditNotePoster::class)->remainingOn($invoice->refresh()));

        $balance = app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse('2026-12-31'));
        $this->assertSame('0.0000', $this->sectionTotal($balance, 'assets'));
    }

    #[Test]
    public function the_rate_credited_is_the_rate_the_invoice_was_raised_at(): void
    {
        // The defect that would have been invisible: crediting a 5% invoice
        // after the rate moved to 15% would hand back three times the tax ever
        // collected, in a perfectly balanced entry.
        $standard = Tax::query()->where('category', TaxCategory::Standard)->firstOrFail();
        $standard->update(['rate' => '5']);

        $invoice = $this->approvedInvoice([['quantity' => '1', 'unit_price' => '100.00']]);
        $this->assertSame('5.0000', $invoice->tax_total);

        // The rate changes between the invoice and the credit note.
        $standard->update(['rate' => '15']);

        $note = $this->approvedCreditNote($invoice, [['quantity' => '1', 'unit_price' => '100.00']]);

        $this->assertSame('5.0000', $note->tax_total);
        $this->assertSame('5.0000', $this->lineOn($note, SystemAccount::VatOutputPayable, 'debit'));
    }

    #[Test]
    public function credit_notes_are_numbered_in_their_own_series(): void
    {
        // The allocator applies its defaults only when it creates the counter
        // row, so sharing the invoice's key would silently hand out invoice
        // numbers — and leave gaps in a series that must not have them.
        $this->approvedInvoice([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->assertSame('CN-00001', app(CreditNotePoster::class)->nextReference());
        $this->assertSame('CN-00002', app(CreditNotePoster::class)->nextReference());
    }

    #[Test]
    public function crediting_more_than_remains_is_refused(): void
    {
        $invoice = $this->approvedInvoice([['quantity' => '10', 'unit_price' => '100.00']]);

        $this->approvedCreditNote($invoice, [['quantity' => '8', 'unit_price' => '100.00']]);

        $this->expectException(CreditNoteRejected::class);

        $this->approvedCreditNote($invoice, [['quantity' => '5', 'unit_price' => '100.00']]);
    }

    #[Test]
    public function crediting_a_different_customer_is_refused(): void
    {
        // The ledger cannot see this at all: receivable is one control account
        // and the journal line carries no contact.
        $invoice = $this->approvedInvoice([['quantity' => '1', 'unit_price' => '100.00']]);

        $other = Contact::create(['contact_name' => 'عميل آخر']);

        $note = $this->draftCreditNote($invoice, [['quantity' => '1', 'unit_price' => '100.00']]);
        $note->forceFill(['contact_id' => $other->getKey()])->save();

        $this->expectException(CreditNoteRejected::class);

        app(CreditNotePoster::class)->approve($note->refresh());
    }

    #[Test]
    public function crediting_a_draft_invoice_is_refused(): void
    {
        // Whitelisted on Approved rather than blacklisted on Draft, so a voided
        // invoice — whose entry has already been reversed — cannot slip past.
        $invoice = $this->draftInvoice([['quantity' => '1', 'unit_price' => '100.00']]);

        $this->expectException(CreditNoteRejected::class);

        $this->approvedCreditNote($invoice, [['quantity' => '1', 'unit_price' => '100.00']]);
    }

    #[Test]
    public function a_credit_note_dated_before_its_invoice_is_refused(): void
    {
        // It would reduce revenue and output VAT in a period before the supply
        // was recognised, restating a return that may already be filed.
        $invoice = $this->approvedInvoice([['quantity' => '1', 'unit_price' => '100.00']]);

        $note = $this->draftCreditNote($invoice, [['quantity' => '1', 'unit_price' => '100.00']]);
        $note->forceFill(['issue_date' => CarbonImmutable::parse('2026-01-01')])->save();

        $this->expectException(CreditNoteRejected::class);

        app(CreditNotePoster::class)->approve($note->refresh());
    }

    #[Test]
    public function a_wholly_zero_rated_credit_note_posts_two_lines(): void
    {
        $invoice = $this->approvedInvoice([
            ['quantity' => '1', 'unit_price' => '100.00', 'category' => TaxCategory::ZeroRated],
        ]);

        $note = $this->approvedCreditNote($invoice, [
            ['quantity' => '1', 'unit_price' => '100.00', 'category' => TaxCategory::ZeroRated],
        ]);

        $this->assertSame('0.0000', $note->tax_total);
        $this->assertCount(2, $note->journalEntry->lines);
        $this->assertTrue($note->journalEntry->isBalanced());
    }

    #[Test]
    public function a_credit_note_against_an_external_invoice_needs_no_parent(): void
    {
        // ZATCA permits crediting an invoice raised on paper or before the
        // company was on any system. The reference is still required.
        $note = SalesCreditNote::create([
            'reference' => app(CreditNotePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'original_invoice_number' => 'PAPER-2024-118',
            'issue_date' => CarbonImmutable::parse('2026-03-20'),
            'due_date' => CarbonImmutable::parse('2026-03-20'),
            'event_date' => CarbonImmutable::parse('2026-03-18'),
            'reason_code' => CreditNoteReason::GoodsReturn,
            'reason_text' => 'إرجاع بضاعة عن فاتورة ورقية سابقة',
        ]);

        SalesCreditNoteItem::create([
            'sales_credit_note_id' => $note->getKey(),
            'product_name' => 'بضاعة مرتجعة',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_id' => Tax::query()->where('category', TaxCategory::Standard)->value('id'),
        ]);

        $approved = app(CreditNotePoster::class)->approve(
            app(CreditNoteRecalculator::class)->recalculate($note->refresh()),
        );

        $this->assertNull($approved->parent_id);
        $this->assertSame('115.0000', $approved->total);
        $this->assertTrue($approved->journalEntry->isBalanced());
    }

    #[Test]
    public function an_approved_credit_note_is_no_longer_recalculated(): void
    {
        $invoice = $this->approvedInvoice([['quantity' => '1', 'unit_price' => '100.00']]);
        $note = $this->approvedCreditNote($invoice, [['quantity' => '1', 'unit_price' => '100.00']]);

        $note->items()->first()->forceFill(['quantity' => '99'])->save();

        $unchanged = app(CreditNoteRecalculator::class)->recalculate($note->refresh());

        $this->assertSame('100.0000', $unchanged->subtotal_net);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedInvoice(array $lines): SalesInvoice
    {
        return app(SalesInvoicePoster::class)->approve($this->draftInvoice($lines));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftInvoice(array $lines): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'reference' => app(SalesInvoicePoster::class)->nextReference(),
            'contact_id' => $this->customer->getKey(),
            'issue_date' => CarbonImmutable::parse('2026-03-15'),
            'due_date' => CarbonImmutable::parse('2026-04-15'),
            'supply_date' => CarbonImmutable::parse('2026-03-15'),
        ]);

        foreach ($lines as $line) {
            SalesInvoiceItem::create([
                'sales_invoice_id' => $invoice->getKey(),
                'product_id' => $this->product()->getKey(),
                'product_name' => 'كرسي مكتب',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
                'tax_id' => Tax::query()->where('category', $line['category'] ?? TaxCategory::Standard)->value('id'),
            ]);
        }

        return app(SalesInvoiceRecalculator::class)->recalculate($invoice->refresh());
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function approvedCreditNote(SalesInvoice $invoice, array $lines): SalesCreditNote
    {
        return app(CreditNotePoster::class)->approve($this->draftCreditNote($invoice, $lines));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function draftCreditNote(SalesInvoice $invoice, array $lines): SalesCreditNote
    {
        $note = SalesCreditNote::create([
            'reference' => app(CreditNotePoster::class)->nextReference(),
            'contact_id' => $invoice->contact_id,
            'parent_id' => $invoice->getKey(),
            'original_invoice_number' => $invoice->reference,
            'original_invoice_date' => $invoice->issue_date,
            'issue_date' => CarbonImmutable::parse('2026-03-20'),
            'due_date' => CarbonImmutable::parse('2026-03-20'),
            'event_date' => CarbonImmutable::parse('2026-03-18'),
            'reason_code' => CreditNoteReason::GoodsReturn,
            'reason_text' => 'إرجاع بضاعة',
        ]);

        $invoiceItems = $invoice->items()->get()->values();

        foreach ($lines as $index => $line) {
            SalesCreditNoteItem::create([
                'sales_credit_note_id' => $note->getKey(),
                // The link that makes a per-line check possible at all.
                'sales_invoice_item_id' => $invoiceItems[$index]?->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'is_inclusive' => $line['is_inclusive'] ?? false,
            ]);
        }

        return app(CreditNoteRecalculator::class)->recalculate($note->refresh());
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

    private function lineOn(SalesCreditNote $note, SystemAccount $role, string $side): string
    {
        $accountId = app(AccountRegistry::class)->get($role)->getKey();
        $line = $note->journalEntry->lines->firstWhere('account_id', $accountId);

        return $line === null ? '0.0000' : (string) $line->{$side};
    }

    private function sectionTotal(object $statement, string $key): string
    {
        foreach ($statement->sections as $section) {
            if ($section->key === $key) {
                return $section->totals[0];
            }
        }

        $this->fail("No '{$key}' section.");
    }
}
