<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\SystemAccount;
use App\Models\Account;
use App\Models\Company;
use App\Services\Accounting\AccountRegistry;
use App\Services\Accounting\ChartOfAccountsTemplate;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\Exceptions\AccountStructureViolation;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Reports\TrialBalance;
use App\Services\Accounting\Reports\TrialBalanceRow;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The trial balance, over the template chart of accounts.
 *
 * The report exists to answer one question — do debits equal credits — so most
 * of what follows checks that it keeps answering yes as activity accumulates,
 * and that the figures it shows reconcile to the entries behind them.
 */
final class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private JournalPoster $poster;

    private TrialBalance $report;

    private AccountRegistry $accounts;

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

        app(ChartOfAccountsTemplate::class)->applyTo($this->company);
        app(FiscalCalendar::class)->createYear($this->company, 2026);

        $this->poster = app(JournalPoster::class);
        $this->report = app(TrialBalance::class);
        $this->accounts = app(AccountRegistry::class);
    }

    #[Test]
    public function the_template_creates_a_complete_chart(): void
    {
        $this->assertGreaterThan(40, Account::query()->count());

        // Every role the platform posts to must resolve.
        $this->assertSame([], $this->accounts->missing());
    }

    #[Test]
    public function the_template_is_idempotent(): void
    {
        $before = Account::query()->count();

        $created = app(ChartOfAccountsTemplate::class)->applyTo($this->company);

        $this->assertSame(0, $created);
        $this->assertSame($before, Account::query()->count());
    }

    #[Test]
    public function reapplying_the_template_does_not_overwrite_renamed_accounts(): void
    {
        $cash = Account::query()->where('code', '1110')->firstOrFail();
        $cash->update(['name' => 'الصندوق الرئيسي']);

        app(ChartOfAccountsTemplate::class)->applyTo($this->company);

        $this->assertSame('الصندوق الرئيسي', $cash->refresh()->name);
    }

    #[Test]
    public function group_accounts_do_not_accept_postings_but_leaves_do(): void
    {
        $assets = Account::query()->where('code', '1000')->firstOrFail();
        $cash = Account::query()->where('code', '1110')->firstOrFail();

        $this->assertFalse($assets->is_postable);
        $this->assertTrue($cash->is_postable);
    }

    #[Test]
    public function system_accounts_resolve_by_role_not_by_code(): void
    {
        $vat = $this->accounts->get(SystemAccount::VatOutputPayable);

        $this->assertSame('2120', $vat->code);

        // Renumbering must not break resolution — the failure mode that tied
        // the predecessor's invoicing to one particular chart.
        $vat->update(['code' => '2999']);
        $this->accounts->flush();

        $this->assertSame(
            $vat->getKey(),
            $this->accounts->get(SystemAccount::VatOutputPayable)->getKey(),
        );
    }

    #[Test]
    public function a_system_account_cannot_be_deleted(): void
    {
        $vat = $this->accounts->get(SystemAccount::VatOutputPayable);

        $this->expectException(AccountStructureViolation::class);

        $vat->delete();
    }

    #[Test]
    public function an_empty_ledger_produces_a_balanced_report(): void
    {
        $totals = $this->report->totals($this->buildReport());

        $this->assertTrue($totals['balanced']);
        $this->assertSame('0.0000', $totals['closing_debit']);
    }

    #[Test]
    public function the_trial_balance_ties_after_a_sale(): void
    {
        // A VAT-inclusive sale, posted the way Phase 3 will post it: revenue
        // credited net, tax credited to its own liability account.
        $this->postSale('1000.00', '150.00');

        $rows = $this->buildReport();
        $totals = $this->report->totals($rows);

        $this->assertTrue($totals['balanced'], 'Debits and credits must agree.');
        $this->assertSame('1150.0000', $totals['closing_debit']);
        $this->assertSame('1150.0000', $totals['closing_credit']);
    }

    #[Test]
    public function revenue_is_recorded_net_of_tax_and_vat_reaches_its_own_account(): void
    {
        $this->postSale('1000.00', '150.00');

        $rows = $this->buildReport();

        $revenue = $this->rowFor($rows, '4100');
        $vat = $this->rowFor($rows, '2120');
        $cash = $this->rowFor($rows, '1110');

        // The defect that motivated the rebuild: the predecessor credited
        // revenue with 1150 and never posted the tax at all.
        $this->assertSame('1000.0000', $revenue->closingCredit);
        $this->assertSame('150.0000', $vat->closingCredit);
        $this->assertSame('1150.0000', $cash->closingDebit);
    }

    #[Test]
    public function the_vat_liability_is_derivable_from_the_ledger(): void
    {
        $this->postSale('1000.00', '150.00');
        $this->postPurchase('400.00', '60.00');

        $rows = $this->buildReport();

        $output = $this->rowFor($rows, '2120')->closingCredit;
        $input = $this->rowFor($rows, '1150')->closingDebit;

        $this->assertSame('150.0000', $output);
        $this->assertSame('60.0000', $input);

        // The return is the difference, read from the ledger rather than from
        // the invoice table — so it reconciles to the trial balance by
        // construction.
        $this->assertSame('90.0000', bcsub($output, $input, 4));
    }

    #[Test]
    public function it_separates_opening_balances_from_period_movement(): void
    {
        $this->postSale('1000.00', '150.00', CarbonImmutable::parse('2026-01-15'));
        $this->postSale('500.00', '75.00', CarbonImmutable::parse('2026-03-10'));

        $rows = $this->buildReport(
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-31'),
        );

        $cash = $this->rowFor($rows, '1110');

        // January's sale is opening; March's is movement.
        $this->assertSame('1150.0000', $cash->openingDebit);
        $this->assertSame('575.0000', $cash->periodDebit);
        $this->assertSame('1725.0000', $cash->closingDebit);
    }

    #[Test]
    public function a_reversal_returns_every_balance_to_zero(): void
    {
        $entry = $this->postSale('1000.00', '150.00');

        $this->poster->reverse($entry);

        $rows = $this->buildReport();
        $totals = $this->report->totals($rows);

        $this->assertTrue($totals['balanced']);
        // Both sides of every affected account net out.
        $this->assertSame('0.0000', $totals['closing_debit']);
        $this->assertSame('0.0000', $totals['closing_credit']);
    }

    #[Test]
    public function drafts_are_excluded_from_the_ledger(): void
    {
        $this->poster->draft(
            date: CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->code('1110'), '5000'),
                JournalLineData::credit($this->code('4100'), '5000'),
            ],
        );

        $totals = $this->report->totals($this->buildReport());

        // A trial balance containing unposted work would not be a trial balance.
        $this->assertSame('0.0000', $totals['closing_debit']);
    }

    #[Test]
    public function it_stays_balanced_across_many_entries(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->postSale((string) ($i * 37), (string) ($i * 37 * 0.15));
        }

        $totals = $this->report->totals($this->buildReport());

        $this->assertTrue($totals['balanced']);
    }

    #[Test]
    public function it_scopes_to_the_current_company(): void
    {
        $this->postSale('1000.00', '150.00');

        $other = Company::create(['name' => 'Globex Industrial']);
        app(ChartOfAccountsTemplate::class)->applyTo($other);

        $rows = app(CompanyContext::class)->forCompany(
            $other,
            fn () => $this->report->build(
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2026-12-31'),
            ),
        );

        // Acme's sale must not appear in Globex's books.
        $this->assertCount(0, $rows);
    }

    /**
     * @return Collection<int, TrialBalanceRow>
     */
    private function buildReport(?CarbonImmutable $from = null, ?CarbonImmutable $to = null)
    {
        return $this->report->build(
            $from ?? CarbonImmutable::parse('2026-01-01'),
            $to ?? CarbonImmutable::parse('2026-12-31'),
        );
    }

    /**
     * @param  Collection<int, TrialBalanceRow>  $rows
     */
    private function rowFor($rows, string $code): TrialBalanceRow
    {
        return $rows->firstWhere('code', $code)
            ?? $this->fail("No trial balance row for account {$code}.");
    }

    private function postSale(string $net, string $tax, ?CarbonImmutable $date = null)
    {
        $gross = bcadd($net, $tax, 4);

        return $this->poster->post(
            date: $date ?? CarbonImmutable::parse('2026-03-15'),
            lines: [
                JournalLineData::debit($this->code('1110'), $gross),
                JournalLineData::credit($this->code('4100'), $net),
                JournalLineData::credit(
                    $this->accounts->get(SystemAccount::VatOutputPayable)->getKey(),
                    $tax,
                ),
            ],
            description: 'Sale',
        );
    }

    private function postPurchase(string $net, string $tax, ?CarbonImmutable $date = null)
    {
        $gross = bcadd($net, $tax, 4);

        return $this->poster->post(
            date: $date ?? CarbonImmutable::parse('2026-03-20'),
            lines: [
                JournalLineData::debit($this->code('5300'), $net),
                JournalLineData::debit(
                    $this->accounts->get(SystemAccount::VatInputRecoverable)->getKey(),
                    $tax,
                ),
                JournalLineData::credit($this->code('1110'), $gross),
            ],
            description: 'Purchase',
        );
    }

    private function code(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->getKey();
    }
}
