<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\JournalEntryStatus;
use App\Enums\PeriodStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\Exceptions\LedgerImmutable;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPoster;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The posting engine.
 *
 * Several of these are direct regressions against the predecessor: numbering
 * derived from COUNT(*), reversals sharing the primary series, and posted
 * entries being editable.
 */
final class JournalPosterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private JournalPoster $poster;

    private Account $cash;

    private Account $revenue;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Acme Trading',
            'base_currency' => 'SAR',
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
        ]);

        app(CompanyContext::class)->set($this->company);

        $this->poster = app(JournalPoster::class);

        app(FiscalCalendar::class)->createYear($this->company, 2026);

        $this->cash = $this->account('1100', 'Cash', AccountType::Asset);
        $this->revenue = $this->account('4000', 'Sales', AccountType::Revenue);
        $this->expense = $this->account('5000', 'Rent', AccountType::Expense);
    }

    #[Test]
    public function it_posts_a_balanced_entry(): void
    {
        $entry = $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '1000.00'),
                JournalLineData::credit($this->revenue->getKey(), '1000.00'),
            ],
            description: 'Cash sale',
        );

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('1000.0000', (string) $entry->total_debit);
        $this->assertCount(2, $entry->lines);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->accounting_period_id);
    }

    #[Test]
    public function it_refuses_an_unbalanced_entry(): void
    {
        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '1000.00'),
                JournalLineData::credit($this->revenue->getKey(), '999.99'),
            ],
        );
    }

    #[Test]
    public function it_detects_imbalance_below_the_minor_unit(): void
    {
        // The case floating point would silently accept.
        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '0.1000'),
                JournalLineData::credit($this->revenue->getKey(), '0.1001'),
            ],
        );
    }

    #[Test]
    public function it_refuses_a_line_carrying_both_sides(): void
    {
        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                new JournalLineData($this->cash->getKey(), debit: '100', credit: '100'),
                JournalLineData::credit($this->revenue->getKey(), '100'),
            ],
        );
    }

    #[Test]
    public function it_refuses_a_negative_amount(): void
    {
        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '-100'),
                JournalLineData::credit($this->revenue->getKey(), '-100'),
            ],
        );
    }

    #[Test]
    public function it_refuses_fewer_than_two_lines(): void
    {
        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [JournalLineData::debit($this->cash->getKey(), '100')],
        );
    }

    #[Test]
    public function it_refuses_to_post_to_a_group_account(): void
    {
        $parent = $this->account('6000', 'Overheads', AccountType::Expense);
        $child = $this->account('6100', 'Utilities', AccountType::Expense, $parent);

        // Gaining a child demotes the parent to a group account.
        $this->assertFalse($parent->refresh()->is_postable);
        $this->assertTrue($child->is_postable);

        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($parent->getKey(), '100'),
                JournalLineData::credit($this->cash->getKey(), '100'),
            ],
        );
    }

    #[Test]
    public function it_refuses_to_post_into_a_closed_period(): void
    {
        AccountingPeriod::query()
            ->whereDate('start_date', '<=', '2026-03-15')
            ->whereDate('end_date', '>=', '2026-03-15')
            ->firstOrFail()
            ->update(['status' => PeriodStatus::Closed]);

        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '100'),
                JournalLineData::credit($this->revenue->getKey(), '100'),
            ],
        );
    }

    #[Test]
    public function it_refuses_to_post_where_no_period_exists(): void
    {
        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2031-01-01'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '100'),
                JournalLineData::credit($this->revenue->getKey(), '100'),
            ],
        );
    }

    #[Test]
    public function entry_numbers_are_sequential_and_gapless(): void
    {
        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $this->postSimple()->number;
        }

        $this->assertSame([
            'JE-2026-00001',
            'JE-2026-00002',
            'JE-2026-00003',
            'JE-2026-00004',
            'JE-2026-00005',
        ], $numbers);
    }

    #[Test]
    public function a_failed_post_does_not_consume_a_number(): void
    {
        $this->postSimple();

        try {
            $this->poster->post(
                date: CarbonImmutable::parse('2026-03-15'),
                lines: [
                    JournalLineData::debit($this->cash->getKey(), '100'),
                    JournalLineData::credit($this->revenue->getKey(), '99'),
                ],
            );
        } catch (PostingRejected) {
            // Expected.
        }

        // The predecessor allocated outside the transaction, so this would have
        // produced JE-2026-00003 and left 00002 permanently missing.
        $this->assertSame('JE-2026-00002', $this->postSimple()->number);
    }

    #[Test]
    public function reversals_use_their_own_series(): void
    {
        $original = $this->postSimple();
        $reversal = $this->poster->reverse($original);

        $this->assertSame('JE-2026-00001', $original->number);
        $this->assertSame('REV-2026-00001', $reversal->number);

        // The primary series is untouched by the reversal.
        $this->assertSame('JE-2026-00002', $this->postSimple()->number);
    }

    #[Test]
    public function a_reversal_inverts_every_movement(): void
    {
        $original = $this->postSimple();
        $reversal = $this->poster->reverse($original);

        $originalDebit = $original->lines->firstWhere('account_id', $this->cash->getKey());
        $reversedLine = $reversal->lines->firstWhere('account_id', $this->cash->getKey());

        $this->assertSame('100.0000', (string) $originalDebit->debit);
        $this->assertSame('0.0000', (string) $reversedLine->debit);
        $this->assertSame('100.0000', (string) $reversedLine->credit);

        // The pair nets to nothing, which is the point of a reversal.
        $this->assertSame(
            '0.0000',
            bcadd($original->lines->sum(fn ($l) => (float) $l->signedAmount()) . '', (string) $reversal->lines->sum(fn ($l) => (float) $l->signedAmount()), 4),
        );
    }

    #[Test]
    public function an_entry_cannot_be_reversed_twice(): void
    {
        $original = $this->postSimple();
        $this->poster->reverse($original);

        $this->expectException(PostingRejected::class);

        $this->poster->reverse($original->refresh());
    }

    #[Test]
    public function a_posted_entry_cannot_be_edited(): void
    {
        $entry = $this->postSimple();

        $entry->description = 'Rewriting history';

        $this->expectException(LedgerImmutable::class);

        $entry->save();
    }

    #[Test]
    public function a_posted_entry_cannot_be_deleted(): void
    {
        $entry = $this->postSimple();

        $this->expectException(LedgerImmutable::class);

        $entry->delete();
    }

    #[Test]
    public function a_draft_consumes_no_number_and_stays_out_of_the_ledger(): void
    {
        $draft = $this->poster->draft(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '100'),
                JournalLineData::credit($this->revenue->getKey(), '100'),
            ],
        );

        $this->assertSame(JournalEntryStatus::Draft, $draft->status);
        $this->assertStringStartsWith('DRAFT-', $draft->number);
        $this->assertNull($draft->posted_at);

        // The first posted entry still takes number one.
        $this->assertSame('JE-2026-00001', $this->postSimple()->number);
    }

    #[Test]
    public function a_draft_can_be_edited_and_then_posted(): void
    {
        $draft = $this->poster->draft(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '100'),
                JournalLineData::credit($this->revenue->getKey(), '100'),
            ],
        );

        $draft->description = 'Corrected before posting';
        $draft->save();

        $posted = $this->poster->postDraft($draft->refresh());

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
        $this->assertSame('Corrected before posting', $posted->description);
        $this->assertSame('JE-2026-00001', $posted->number);
    }

    #[Test]
    public function an_entry_cannot_reference_another_companys_account(): void
    {
        $other = Company::create(['name' => 'Globex Industrial']);

        $foreignAccount = app(CompanyContext::class)->forCompany(
            $other,
            fn (): Account => Account::create([
                'code' => '1100',
                'name' => 'Their Cash',
                'type' => AccountType::Asset,
            ]),
        );

        $this->expectException(PostingRejected::class);

        $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($foreignAccount->getKey(), '100'),
                JournalLineData::credit($this->revenue->getKey(), '100'),
            ],
        );
    }

    private function postSimple(): JournalEntry
    {
        return $this->poster->post(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->cash->getKey(), '100'),
                JournalLineData::credit($this->revenue->getKey(), '100'),
            ],
        );
    }

    private function account(string $code, string $name, AccountType $type, ?Account $parent = null): Account
    {
        return Account::create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'parent_id' => $parent?->getKey(),
        ]);
    }
}
