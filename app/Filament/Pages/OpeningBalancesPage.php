<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\Accounting\Exceptions\OpeningBalanceRejected;
use App\Services\Accounting\OpeningBalances;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Entering the balances a company arrives with.
 *
 * The screen is a transcription surface and nothing more: it collects a figure
 * per account and hands the whole picture to {@see OpeningBalances}, which owns
 * every rule about what may be entered, what balances against what, and when an
 * entry may still be changed. Nothing here decides anything.
 *
 * A plain table rather than a Filament repeater. The rows are not a collection
 * the user adds to and removes from — they are the company's own chart, fixed
 * for the length of the task, and a repeater would offer add and delete
 * controls for accounts that cannot be added or deleted.
 */
class OpeningBalancesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquare;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.opening-balances';

    public ?string $fiscalYearId = null;

    /**
     * Keyed by account id, each holding a debit and a credit.
     *
     * @var array<string, array{debit: ?string, credit: ?string}>
     */
    public array $balances = [];

    public static function getNavigationGroup(): ?string
    {
        return __('accounting.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting.opening_balances.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('accounting.opening_balances.title');
    }

    public function getSubheading(): ?string
    {
        return __('accounting.opening_balances.subheading');
    }

    public function mount(): void
    {
        $this->fiscalYearId = FiscalYear::query()
            ->orderBy('start_date')
            ->value('id');

        $this->loadExisting();
    }

    /**
     * Reload when the reader switches year, so the figures always belong to the
     * year named above them.
     */
    public function updatedFiscalYearId(): void
    {
        $this->loadExisting();
    }

    /**
     * @return Collection<int, Account>
     */
    public function getAccounts(): Collection
    {
        return app(OpeningBalances::class)->eligibleAccounts();
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    public function getFiscalYears(): Collection
    {
        return FiscalYear::query()->orderBy('start_date')->get();
    }

    public function getFiscalYear(): ?FiscalYear
    {
        return $this->fiscalYearId === null
            ? null
            : FiscalYear::query()->find($this->fiscalYearId);
    }

    /**
     * The existing entry, whether it is still a draft or already posted.
     */
    public function getEntry(): ?JournalEntry
    {
        $year = $this->getFiscalYear();

        return $year === null ? null : app(OpeningBalances::class)->for($year);
    }

    public function isPosted(): bool
    {
        return $this->getEntry()?->isPosted() ?? false;
    }

    /**
     * @return array{debit: string, credit: string, difference: string}
     */
    public function getTotals(): array
    {
        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($this->balances as $amounts) {
            $debit = bcadd($debit, $this->figure($amounts['debit'] ?? null), 4);
            $credit = bcadd($credit, $this->figure($amounts['credit'] ?? null), 4);
        }

        return [
            'debit' => $debit,
            'credit' => $credit,
            'difference' => bcsub($debit, $credit, 4),
        ];
    }

    public function save(): void
    {
        $this->run(function (FiscalYear $year): void {
            app(OpeningBalances::class)->record($year, $this->balances, auth()->id());

            Notification::make()
                ->title(__('accounting.opening_balances.saved'))
                ->success()
                ->send();
        });
    }

    public function commit(): void
    {
        $this->run(function (FiscalYear $year): void {
            app(OpeningBalances::class)->record($year, $this->balances, auth()->id());
            app(OpeningBalances::class)->commit($year, auth()->id());

            Notification::make()
                ->title(__('accounting.opening_balances.committed'))
                ->success()
                ->send();
        });
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('accounting.opening_balances.save'))
                ->color('gray')
                ->hidden(fn (): bool => $this->isPosted())
                ->action('save'),

            Action::make('commit')
                ->label(__('accounting.opening_balances.commit'))
                ->requiresConfirmation()
                ->modalDescription(__('accounting.opening_balances.commit_confirm'))
                ->hidden(fn (): bool => $this->isPosted())
                ->action('commit'),
        ];
    }

    /**
     * Run an action that needs a fiscal year, turning a refusal into a message.
     *
     * The service throws rather than returns, because a refused opening balance
     * is a condition the caller must not continue past. A page, though, is
     * where a person is standing — so it is told what happened instead of being
     * shown a stack trace.
     *
     * @param  callable(FiscalYear): void  $action
     */
    private function run(callable $action): void
    {
        $year = $this->getFiscalYear();

        if ($year === null) {
            Notification::make()
                ->title(__('accounting.opening_balances.no_fiscal_year'))
                ->danger()
                ->send();

            return;
        }

        try {
            $action($year);
        } catch (OpeningBalanceRejected $refusal) {
            Notification::make()
                ->title($refusal->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Load whatever is already recorded, so the screen resumes rather than
     * starting the transcription over.
     */
    private function loadExisting(): void
    {
        $this->balances = [];

        $entry = $this->getEntry();

        if ($entry === null) {
            return;
        }

        foreach ($entry->lines as $line) {
            // Cast rather than trusted: the decimal cast hands back a string at
            // runtime, but the column reads as a float to static analysis, and
            // bcmath silently loses precision on anything that is not a string.
            $debit = (string) $line->debit;
            $credit = (string) $line->credit;

            $this->balances[$line->account_id] = [
                'debit' => bccomp($debit, '0', 4) !== 0 ? $debit : null,
                'credit' => bccomp($credit, '0', 4) !== 0 ? $credit : null,
            ];
        }
    }

    private function figure(?string $value): string
    {
        return blank($value) || ! is_numeric($value) ? '0.0000' : bcadd($value, '0', 4);
    }
}
