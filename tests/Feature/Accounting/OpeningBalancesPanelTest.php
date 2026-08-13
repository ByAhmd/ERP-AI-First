<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\JournalEntryKind;
use App\Filament\Pages\OpeningBalancesPage;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Opening balances through the screen rather than the service.
 *
 * The service is well covered, and that is exactly why this exists: the last
 * two defects a person actually hit in this application — the unset line
 * number, the unbound permission team — both lived in the gap between a
 * service that worked and a screen that used it differently.
 */
final class OpeningBalancesPanelTest extends TestCase
{
    use CreatesDomainFixtures;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Trading');
        $this->admin = $this->makeAdministrator($this->company, 'admin@acme.test');

        $this->actingInPanel($this->admin, $this->company);

        $this->makeChartOfAccounts($this->company);
        $this->makeFiscalYear($this->company, 2026);
    }

    #[Test]
    public function the_screen_renders_with_a_row_for_every_eligible_account(): void
    {
        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            ->assertOk()
            // A permanent account is offered; a revenue account never is.
            ->assertSee('النقد في الصندوق')
            ->assertDontSee('إيرادات المبيعات');
    }

    #[Test]
    public function balances_typed_into_the_screen_reach_the_ledger(): void
    {
        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            ->set('balances', [
                $this->accountId('1110') => ['debit' => '50000', 'credit' => null],
                $this->accountId('3100') => ['debit' => null, 'credit' => '50000'],
            ])
            ->call('commit')
            ->assertHasNoErrors();

        $entry = JournalEntry::query()->where('kind', JournalEntryKind::Opening)->firstOrFail();

        $this->assertTrue($entry->isPosted());
        $this->assertTrue($entry->isBalanced());
        $this->assertSame('50000.0000', $entry->total_debit);
    }

    #[Test]
    public function the_running_difference_is_shown_before_anything_is_committed(): void
    {
        // Asserted on what is rendered rather than on the computed figure: the
        // point of the difference is that the person transcribing sees it while
        // there is still time to find the transposed digit.
        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            ->set('balances', [
                $this->accountId('1110') => ['debit' => '50000', 'credit' => null],
                $this->accountId('3100') => ['debit' => null, 'credit' => '45000'],
            ])
            ->assertSee(__('accounting.opening_balances.unbalanced', ['difference' => '5,000.00']));
    }

    #[Test]
    public function a_refusal_is_reported_rather_than_thrown_at_the_reader(): void
    {
        // Both sides on one account. The service refuses; the screen must say
        // so rather than return a stack trace to someone mid-transcription.
        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            ->set('balances', [
                $this->accountId('1110') => ['debit' => '500', 'credit' => '300'],
            ])
            ->call('save')
            ->assertOk();

        $this->assertSame(0, JournalEntry::query()->count());
    }

    #[Test]
    public function reopening_the_screen_resumes_the_saved_draft(): void
    {
        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            ->set('balances', [
                $this->accountId('1110') => ['debit' => '50000', 'credit' => null],
                $this->accountId('3100') => ['debit' => null, 'credit' => '50000'],
            ])
            ->call('save');

        // Transcribing a chart of accounts is not done in one sitting.
        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            ->assertSet('balances.'.$this->accountId('1110').'.debit', '50000.0000');
    }

    #[Test]
    public function a_posted_entry_locks_the_screen(): void
    {
        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            ->set('balances', [
                $this->accountId('1110') => ['debit' => '50000', 'credit' => null],
                $this->accountId('3100') => ['debit' => null, 'credit' => '50000'],
            ])
            ->call('commit');

        $number = JournalEntry::query()->value('number');

        Livewire::actingAs($this->admin)
            ->test(OpeningBalancesPage::class)
            // The notice replaces the inputs, so seeing it is the assertion
            // that the screen is locked rather than merely reporting itself so.
            ->assertSee(__('accounting.opening_balances.posted_notice', ['number' => $number]))
            ->assertDontSee('erp-ob__input', escape: false);
    }

    private function accountId(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->getKey();
    }
}
