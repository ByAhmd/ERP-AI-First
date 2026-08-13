<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\PeriodStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\Exceptions\PeriodTransitionRejected;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\FiscalYearCloser;
use App\Services\Accounting\JournalPoster;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Closing and reopening a fiscal year.
 *
 * Extracted from the Filament table that displayed the buttons. There it ran as
 * two separate writes with no transaction, so a failure between them left a
 * closed year sitting above open periods.
 */
final class FiscalYearCloserTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $year;

    private FiscalYearCloser $closer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme Trading']);
        app(CompanyContext::class)->set($this->company);

        $this->year = app(FiscalCalendar::class)->createYear($this->company, 2026);
        $this->closer = app(FiscalYearCloser::class);
    }

    #[Test]
    public function closing_a_year_seals_every_period_within_it(): void
    {
        $closed = $this->closer->close($this->year);

        $this->assertSame(PeriodStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);

        $open = AccountingPeriod::query()
            ->where('fiscal_year_id', $this->year->getKey())
            ->where('status', PeriodStatus::Open->value)
            ->count();

        // An open period beneath a closed year is contradictory.
        $this->assertSame(0, $open);
    }

    #[Test]
    public function closing_records_who_closed_it(): void
    {
        $user = User::create([
            'name' => 'Closer',
            'email' => 'closer@acme.test',
            'password' => 'password',
        ]);

        $closed = $this->closer->close($this->year, $user->getKey());

        $this->assertSame($user->getKey(), $closed->closed_by_id);
    }

    #[Test]
    public function a_closed_year_refuses_postings(): void
    {
        $cash = Account::create(['code' => '1110', 'name' => 'Cash', 'type' => AccountType::Asset]);
        $sales = Account::create(['code' => '4100', 'name' => 'Sales', 'type' => AccountType::Revenue]);

        $this->closer->close($this->year);

        $this->expectException(PostingRejected::class);

        app(JournalPoster::class)->post(
            date: CarbonImmutable::parse('2026-06-15'),
            lines: [
                JournalLineData::debit($cash->getKey(), '100'),
                JournalLineData::credit($sales->getKey(), '100'),
            ],
        );
    }

    #[Test]
    public function a_year_cannot_be_closed_twice(): void
    {
        $this->closer->close($this->year);

        $this->expectException(PeriodTransitionRejected::class);

        $this->closer->close($this->year->refresh());
    }

    #[Test]
    public function reopening_restores_the_year_and_its_periods(): void
    {
        $this->closer->close($this->year);
        $reopened = $this->closer->reopen($this->year->refresh());

        $this->assertSame(PeriodStatus::Open, $reopened->status);
        $this->assertNull($reopened->closed_at);

        $closed = AccountingPeriod::query()
            ->where('fiscal_year_id', $this->year->getKey())
            ->where('status', PeriodStatus::Closed->value)
            ->count();

        $this->assertSame(0, $closed);
    }

    #[Test]
    public function a_locked_year_cannot_be_reopened(): void
    {
        // Locking follows the year-end transfer to retained earnings. Reopening
        // would double-count it.
        $this->year->forceFill(['status' => PeriodStatus::Locked])->save();

        $this->expectException(PeriodTransitionRejected::class);

        $this->closer->reopen($this->year);
    }

    #[Test]
    public function an_open_year_cannot_be_reopened(): void
    {
        $this->expectException(PeriodTransitionRejected::class);

        $this->closer->reopen($this->year);
    }
}
