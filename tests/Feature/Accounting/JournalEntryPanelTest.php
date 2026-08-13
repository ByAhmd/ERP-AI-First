<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\JournalEntryStatus;
use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * Creating entries through the panel rather than through the service.
 *
 * The repeater writes lines straight through the relationship, so it takes a
 * different path than {@see JournalPoster} and does not inherit what the poster
 * does on the way. A journal entry could therefore be fully covered at the
 * service layer and still fail the moment a person used the screen — which is
 * exactly what happened with the line number.
 */
final class JournalEntryPanelTest extends TestCase
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
        $this->makeFiscalYear($this->company, (int) now()->year);
    }

    #[Test]
    public function an_entry_can_be_created_through_the_form(): void
    {
        // Regression: line_number has no database default, and the repeater
        // does not set it. Creating an entry from the panel failed outright
        // while every service-level test passed.
        Livewire::actingAs($this->admin)
            ->test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => now()->toDateString(),
                'description' => 'مبيعات نقدية',
                'lines' => [
                    ['account_id' => $this->account('1110'), 'debit' => 1150, 'credit' => 0],
                    ['account_id' => $this->account('4100'), 'debit' => 0, 'credit' => 1000],
                    ['account_id' => $this->account('2120'), 'debit' => 0, 'credit' => 150],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::query()->firstOrFail();

        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertCount(3, $entry->lines);
    }

    #[Test]
    public function form_created_lines_are_numbered_contiguously_from_one(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => now()->toDateString(),
                'lines' => [
                    ['account_id' => $this->account('1110'), 'debit' => 500, 'credit' => 0],
                    ['account_id' => $this->account('4100'), 'debit' => 0, 'credit' => 500],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $numbers = JournalEntry::query()->firstOrFail()
            ->lines()->orderBy('line_number')->pluck('line_number')->all();

        $this->assertSame([1, 2], $numbers);
    }

    #[Test]
    public function a_form_created_entry_is_a_draft_that_can_then_be_posted(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => now()->toDateString(),
                'lines' => [
                    ['account_id' => $this->account('1110'), 'debit' => 750, 'credit' => 0],
                    ['account_id' => $this->account('4100'), 'debit' => 0, 'credit' => 750],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::query()->firstOrFail();

        // Saving must not post. Posting is a separate, deliberate act.
        $this->assertTrue($entry->isDraft());
        $this->assertStringStartsWith('DRAFT-', $entry->number);

        $posted = app(JournalPoster::class)->postDraft($entry, $this->admin->getKey());

        $this->assertTrue($posted->isPosted());
        $this->assertStringStartsWith('JE-', $posted->number);
        $this->assertTrue($posted->isBalanced());
    }

    #[Test]
    public function each_line_is_stamped_with_the_current_company(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => now()->toDateString(),
                'lines' => [
                    ['account_id' => $this->account('1110'), 'debit' => 100, 'credit' => 0],
                    ['account_id' => $this->account('4100'), 'debit' => 0, 'credit' => 100],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::query()->firstOrFail();

        $this->assertSame($this->company->getKey(), $entry->company_id);

        foreach ($entry->lines as $line) {
            $this->assertSame($this->company->getKey(), $line->company_id);
        }
    }

    private function account(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->getKey();
    }
}
