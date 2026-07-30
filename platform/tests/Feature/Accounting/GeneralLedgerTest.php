<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Dimension;
use App\Models\DimensionValue;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Reports\GeneralLedger;
use App\Services\Accounting\Reports\TrialBalance;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The general ledger.
 *
 * The report that explains how an account reached its balance. Its closing
 * figure must agree with the trial balance for the same date — if the two ever
 * disagree, one of them is computing from something other than the ledger.
 */
final class GeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private JournalPoster $poster;

    private GeneralLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Trading']);
        app(CompanyContext::class)->set($this->company);

        app(ChartOfAccountsTemplate::class)->applyTo($this->company);
        app(FiscalCalendar::class)->createYear($this->company, 2026);

        $this->poster = app(JournalPoster::class);
        $this->ledger = app(GeneralLedger::class);
    }

    #[Test]
    public function it_lists_movements_in_date_order_with_a_running_balance(): void
    {
        $this->postSale('1000', CarbonImmutable::parse('2026-02-10'));
        $this->postSale('500', CarbonImmutable::parse('2026-03-05'));
        $this->postPayment('300', CarbonImmutable::parse('2026-03-20'));

        $movements = $this->ledgerFor('1110');

        $this->assertCount(3, $movements);
        $this->assertSame('1000.0000', $movements[0]->balance);
        $this->assertSame('1500.0000', $movements[1]->balance);
        // The payment reduces cash.
        $this->assertSame('1200.0000', $movements[2]->balance);
    }

    #[Test]
    public function an_empty_account_produces_no_movements(): void
    {
        $movements = $this->ledgerFor('1120');

        $this->assertCount(0, $movements);
    }

    #[Test]
    public function movements_before_the_window_become_the_opening_balance(): void
    {
        $this->postSale('1000', CarbonImmutable::parse('2026-01-15'));
        $this->postSale('400', CarbonImmutable::parse('2026-03-10'));

        $account = $this->account('1110');

        $opening = $this->ledger->openingBalance(
            $account,
            CarbonImmutable::parse('2026-03-01'),
        );

        $movements = $this->ledger->movements(
            $account,
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        $this->assertSame('1000.0000', $opening);
        $this->assertCount(1, $movements);
        // The running balance continues from the opening rather than restarting.
        $this->assertSame('1400.0000', $movements->first()->balance);
    }

    #[Test]
    public function the_closing_balance_ties_to_the_trial_balance(): void
    {
        // The assertion that matters most: two reports, one ledger, and they
        // must agree or one of them is wrong.
        $this->postSale('1000', CarbonImmutable::parse('2026-02-10'));
        $this->postSale('750', CarbonImmutable::parse('2026-03-05'));
        $this->postPayment('420', CarbonImmutable::parse('2026-03-20'));

        $account = $this->account('1110');
        $from = CarbonImmutable::parse('2026-01-01');
        $to = CarbonImmutable::parse('2026-12-31');

        $movements = $this->ledger->movements($account, $from, $to);
        $summary = $this->ledger->summarise($movements, $this->ledger->openingBalance($account, $from));

        $trialRow = app(TrialBalance::class)
            ->build($from, $to)
            ->firstWhere('code', '1110');

        $this->assertSame($trialRow->closingDebit, $summary['closing']);
    }

    #[Test]
    public function a_credit_balance_reads_in_its_natural_direction(): void
    {
        // A payable at 5,000 credit is 5,000 owed, not minus 5,000.
        $this->postPurchaseOnCredit('5000', CarbonImmutable::parse('2026-03-01'));

        $payables = $this->account('2110');
        $from = CarbonImmutable::parse('2026-01-01');

        $movements = $this->ledger->movements($payables, $from, CarbonImmutable::parse('2026-12-31'));
        $summary = $this->ledger->summarise($movements, $this->ledger->openingBalance($payables, $from));

        $this->assertSame('-5000.0000', $summary['closing']);
        $this->assertSame(
            '5000.0000',
            $this->ledger->inNaturalDirection($payables, $summary['closing']),
        );
    }

    #[Test]
    public function period_totals_reconcile_opening_to_closing(): void
    {
        $this->postSale('1000', CarbonImmutable::parse('2026-02-10'));
        $this->postSale('600', CarbonImmutable::parse('2026-03-05'));
        $this->postPayment('250', CarbonImmutable::parse('2026-03-15'));

        $account = $this->account('1110');
        $from = CarbonImmutable::parse('2026-03-01');
        $to = CarbonImmutable::parse('2026-03-31');

        $movements = $this->ledger->movements($account, $from, $to);
        $summary = $this->ledger->summarise($movements, $this->ledger->openingBalance($account, $from));

        $this->assertSame('1000.0000', $summary['opening']);
        $this->assertSame('600.0000', $summary['debit']);
        $this->assertSame('250.0000', $summary['credit']);
        // opening + debits - credits = closing, which is what makes the column
        // totals on the printed report add up.
        $this->assertSame('1350.0000', $summary['closing']);
    }

    #[Test]
    public function drafts_never_appear(): void
    {
        $this->poster->draft(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->account('1110')->getKey(), '9999'),
                JournalLineData::credit($this->account('4100')->getKey(), '9999'),
            ],
        );

        $this->assertCount(0, $this->ledgerFor('1110'));
    }

    #[Test]
    public function a_reversal_appears_and_returns_the_balance_to_zero(): void
    {
        $entry = $this->postSale('1000', CarbonImmutable::parse('2026-03-10'));
        $this->poster->reverse($entry, CarbonImmutable::parse('2026-03-12'));

        $movements = $this->ledgerFor('1110');

        // Both sides stay visible. The correction is part of the record.
        $this->assertCount(2, $movements);
        $this->assertSame('0.0000', $movements->last()->balance);
    }

    #[Test]
    public function it_can_be_filtered_to_a_dimension_value(): void
    {
        $project = Dimension::create(['code' => 'PROJ', 'name' => 'Project']);
        $riyadh = DimensionValue::create([
            'dimension_id' => $project->getKey(),
            'code' => 'RY',
            'name' => 'Riyadh',
        ]);
        $jeddah = DimensionValue::create([
            'dimension_id' => $project->getKey(),
            'code' => 'JD',
            'name' => 'Jeddah',
        ]);

        $this->postSale('1000', CarbonImmutable::parse('2026-03-01'), [$project->getKey() => $riyadh->getKey()]);
        $this->postSale('400', CarbonImmutable::parse('2026-03-02'), [$project->getKey() => $jeddah->getKey()]);

        $movements = $this->ledger->movements(
            $this->account('1110'),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
            ['dimension_value_id' => $riyadh->getKey()],
        );

        $this->assertCount(1, $movements);
        $this->assertSame('1000.0000', $movements->first()->balance);
    }

    #[Test]
    public function it_scopes_to_the_current_company(): void
    {
        $this->postSale('1000', CarbonImmutable::parse('2026-03-01'));

        $other = Company::create(['name' => 'Globex Industrial']);
        app(ChartOfAccountsTemplate::class)->applyTo($other);

        $movements = app(CompanyContext::class)->forCompany($other, function () {
            $account = Account::query()->where('code', '1110')->firstOrFail();

            return $this->ledger->movements(
                $account,
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2026-12-31'),
            );
        });

        $this->assertCount(0, $movements);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Services\Accounting\Reports\LedgerMovement>
     */
    private function ledgerFor(string $code)
    {
        return $this->ledger->movements(
            $this->account($code),
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-12-31'),
        );
    }

    /**
     * @param  array<string, string>  $dimensions
     */
    private function postSale(string $amount, CarbonImmutable $date, array $dimensions = [])
    {
        return $this->poster->post(
            date: $date,
            lines: [
                new JournalLineData($this->account('1110')->getKey(), debit: $amount, dimensions: $dimensions),
                new JournalLineData($this->account('4100')->getKey(), credit: $amount, dimensions: $dimensions),
            ],
            description: 'Sale',
        );
    }

    private function postPayment(string $amount, CarbonImmutable $date)
    {
        return $this->poster->post(
            date: $date,
            lines: [
                JournalLineData::debit($this->account('5300')->getKey(), $amount),
                JournalLineData::credit($this->account('1110')->getKey(), $amount),
            ],
            description: 'Rent paid',
        );
    }

    private function postPurchaseOnCredit(string $amount, CarbonImmutable $date)
    {
        return $this->poster->post(
            date: $date,
            lines: [
                JournalLineData::debit($this->account('5300')->getKey(), $amount),
                JournalLineData::credit($this->account('2110')->getKey(), $amount),
            ],
            description: 'Rent on credit',
        );
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }
}
