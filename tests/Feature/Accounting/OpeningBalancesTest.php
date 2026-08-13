<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\JournalEntryKind;
use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\Exceptions\OpeningBalanceRejected;
use App\Services\Accounting\OpeningBalances;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\FinancialStatement;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * The balances a company arrives with.
 *
 * This is the first thing a company migrating off Qoyod has to do, and the
 * hardest one to correct afterwards: an opening balance is the foundation every
 * later figure stands on, so a mistake here is not one wrong entry but every
 * report being wrong by the same amount indefinitely.
 */
final class OpeningBalancesTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private FiscalYear $year;

    private OpeningBalances $balances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeAccountingCompany(2026);
        $this->year = FiscalYear::query()->firstOrFail();
        $this->balances = app(OpeningBalances::class);
    }

    #[Test]
    public function only_accounts_that_carry_forward_are_offered(): void
    {
        $offered = $this->balances->eligibleAccounts();

        // Revenue and expenses restart each year. Offering them would let
        // someone put last year's sales into this year's income statement.
        $this->assertTrue($offered->every(fn (Account $a): bool => $a->type->isPermanent()));

        $this->assertTrue($offered->contains(fn (Account $a): bool => $a->code === '1110'));
        $this->assertFalse($offered->contains(fn (Account $a): bool => $a->code === '4100'));

        // Group accounts hold no postings of their own.
        $this->assertFalse($offered->contains(fn (Account $a): bool => $a->code === '1000'));

        // The suspense account receives the difference; typing into it would
        // let a reader balance the sheet against the account that reports it
        // as unbalanced.
        $suspense = app(AccountRegistry::class)->get(SystemAccount::OpeningBalanceSuspense);
        $this->assertFalse($offered->contains(fn (Account $a): bool => $a->is($suspense)));
    }

    #[Test]
    public function a_balanced_set_posts_without_touching_suspense(): void
    {
        $entry = $this->record([
            '1110' => ['debit' => '50000'],
            '1130' => ['debit' => '30000'],
            '2110' => ['credit' => '20000'],
            '3100' => ['credit' => '60000'],
        ]);

        $this->assertSame(JournalEntryKind::Opening, $entry->kind);
        $this->assertTrue($entry->isDraft());
        $this->assertCount(4, $entry->lines);

        $posted = $this->balances->commit($this->year);

        $this->assertTrue($posted->isPosted());
        $this->assertTrue($posted->isBalanced());
        $this->assertFalse($this->suspenseHasLines());
    }

    #[Test]
    public function a_difference_is_carried_to_suspense_rather_than_refused_or_hidden(): void
    {
        // Transcribing a real company's books rarely balances first time.
        // Refusing would strand the work; folding the difference into capital
        // would misstate what the owners actually put in.
        $entry = $this->record([
            '1110' => ['debit' => '50000'],
            '3100' => ['credit' => '45000'],
        ]);

        $this->assertCount(3, $entry->lines);

        $suspenseLine = $entry->lines->firstWhere(
            'account_id',
            app(AccountRegistry::class)->get(SystemAccount::OpeningBalanceSuspense)->getKey(),
        );

        $this->assertNotNull($suspenseLine);
        $this->assertSame('5000.0000', $suspenseLine->credit);

        $this->assertTrue($this->balances->commit($this->year)->isBalanced());
    }

    #[Test]
    public function the_difference_is_reported_before_it_becomes_a_suspense_balance(): void
    {
        $this->assertSame('5000.0000', $this->balances->difference([
            'a' => ['debit' => '50000'],
            'b' => ['credit' => '45000'],
        ]));

        $this->assertSame('0.0000', $this->balances->difference([
            'a' => ['debit' => '50000'],
            'b' => ['credit' => '50000'],
        ]));
    }

    #[Test]
    public function saving_again_replaces_the_draft_rather_than_adding_to_it(): void
    {
        $this->record(['1110' => ['debit' => '50000'], '3100' => ['credit' => '50000']]);

        // The screen submits the whole picture each time. Merging would leave
        // an account the user had just cleared still carrying its old figure.
        $second = $this->record(['1120' => ['debit' => '70000'], '3100' => ['credit' => '70000']]);

        $this->assertCount(2, $second->lines);
        $this->assertSame(1, $this->openingEntryCount());

        $accountIds = $second->lines->pluck('account_id')->sort()->values()->all();

        $this->assertSame(
            collect([$this->accountId('1120'), $this->accountId('3100')])->sort()->values()->all(),
            $accountIds,
        );
    }

    #[Test]
    public function posted_balances_cannot_be_replaced(): void
    {
        $this->record(['1110' => ['debit' => '50000'], '3100' => ['credit' => '50000']]);
        $this->balances->commit($this->year);

        // Posted opening balances are part of the ledger and share its
        // immutability. Correction is by reversal.
        $this->expectException(OpeningBalanceRejected::class);

        $this->record(['1110' => ['debit' => '99999'], '3100' => ['credit' => '99999']]);
    }

    #[Test]
    public function an_account_cannot_carry_both_sides(): void
    {
        $this->expectException(OpeningBalanceRejected::class);

        $this->record(['1110' => ['debit' => '500', 'credit' => '300']]);
    }

    #[Test]
    public function a_temporary_account_is_refused_even_if_it_reaches_the_service(): void
    {
        // The screen never offers it, but the service is the guard that counts:
        // a payload can be crafted, and revenue in an opening balance would
        // inflate the first income statement the company ever runs.
        $this->expectException(OpeningBalanceRejected::class);

        $this->record(['4100' => ['credit' => '10000']]);
    }

    #[Test]
    public function blank_accounts_are_left_out_of_the_entry_entirely(): void
    {
        $entry = $this->record([
            '1110' => ['debit' => '50000'],
            '1120' => ['debit' => null, 'credit' => null],
            '1130' => ['debit' => '0', 'credit' => '0'],
            '3100' => ['credit' => '50000'],
        ]);

        $this->assertCount(2, $entry->lines);
    }

    #[Test]
    public function nothing_at_all_is_refused_rather_than_posted_empty(): void
    {
        $this->expectException(OpeningBalanceRejected::class);

        $this->record(['1110' => ['debit' => '0']]);
    }

    #[Test]
    public function opening_balances_land_on_the_balance_sheet_and_it_still_balances(): void
    {
        $this->record([
            '1110' => ['debit' => '50000'],
            '2110' => ['credit' => '20000'],
            '3100' => ['credit' => '30000'],
        ]);

        $this->balances->commit($this->year);

        $statement = app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse('2026-01-01'));

        $this->assertTrue($statement->isBalanced());
        $this->assertSame('50000.0000', $this->total($statement, 'assets'));
        $this->assertSame('20000.0000', $this->total($statement, 'liabilities'));
        $this->assertSame('30000.0000', $this->total($statement, 'equity'));
    }

    #[Test]
    public function they_are_dated_the_first_day_of_the_year_they_open(): void
    {
        // The natural date is the day before, but that falls in a year the
        // company may never have opened and the posting gate would refuse it.
        $entry = $this->record(['1110' => ['debit' => '100'], '3100' => ['credit' => '100']]);

        $this->assertSame(
            $this->year->start_date->toDateString(),
            $entry->entry_date->toDateString(),
        );
    }

    #[Test]
    public function they_do_not_reach_another_companys_books(): void
    {
        $this->record(['1110' => ['debit' => '50000'], '3100' => ['credit' => '50000']]);
        $this->balances->commit($this->year);

        $rival = $this->makeOtherCompany('Globex Industrial');
        $this->makeChartOfAccounts($rival);
        $this->makeFiscalYear($rival, 2026);

        // makeOtherCompany restores the bound context, so the statement has to
        // be built inside the rival's own context to be a test of anything.
        $assets = app(CompanyContext::class)->forCompany($rival, function (): string {
            app(AccountRegistry::class)->flush();

            return $this->total(
                app(BalanceSheet::class)->build(asOf: CarbonImmutable::parse('2026-06-30')),
                'assets',
            );
        });

        app(AccountRegistry::class)->flush();

        $this->assertSame('0.0000', $assets);
    }

    /**
     * Translate account codes into the ids the service works in.
     *
     * Keyed by array-key rather than string because PHP casts numeric-string
     * array keys to integers, so '1110' arrives here as 1110. Account ids are
     * ULIDs and never suffer this, which is why the service itself is typed
     * more strictly.
     *
     * @param  array<array-key, array{debit?: string|null, credit?: string|null}>  $byCode
     */
    private function record(array $byCode): JournalEntry
    {
        $balances = [];

        foreach ($byCode as $code => $amounts) {
            // PHP casts numeric-string array keys to integers, so the codes
            // arrive here as ints. Account ids are ULIDs and never suffer this.
            $balances[$this->accountId((string) $code)] = $amounts;
        }

        return $this->balances->record($this->year, $balances);
    }

    private function total(FinancialStatement $statement, string $key): string
    {
        foreach ($statement->sections as $section) {
            if ($section->key === $key) {
                return $section->totals[0];
            }
        }

        $this->fail("The statement has no '{$key}' section.");
    }

    private function suspenseHasLines(): bool
    {
        return app(AccountRegistry::class)
            ->get(SystemAccount::OpeningBalanceSuspense)
            ->journalLines()
            ->exists();
    }

    private function openingEntryCount(): int
    {
        return JournalEntry::query()
            ->where('kind', JournalEntryKind::Opening)
            ->count();
    }

    private function accountId(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->getKey();
    }
}
