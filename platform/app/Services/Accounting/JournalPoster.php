<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Accounting\Data\JournalLineData;
use App\Services\Accounting\Exceptions\PostingRejected;
use App\Support\Tenancy\CompanyContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes entries into the general ledger.
 *
 * The single way anything reaches the ledger. Invoices, payments, payroll and
 * depreciation all come through here, so the rules below are guaranteed for
 * every financial movement in the platform rather than re-implemented per module
 * and forgotten in one of them — which is how the predecessor ended up posting
 * VAT into its revenue accounts.
 *
 * Everything happens in one transaction: number allocation, line writes and
 * total computation share a fate, so a failure leaves neither a half-written
 * entry nor a consumed number.
 *
 * Arithmetic is bcmath on strings throughout. Floating point cannot represent
 * 0.1 exactly, and a ledger that is out by a thousandth is out.
 */
final class JournalPoster
{
    private const SCALE = 4;

    public function __construct(
        private readonly CompanyContext $context,
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumberAllocator $numbers,
        private readonly DimensionAssigner $dimensions,
    ) {}

    /**
     * Create and immediately post an entry.
     *
     * @param  list<JournalLineData>  $lines
     */
    public function post(
        DateTimeInterface $date,
        array $lines,
        ?string $description = null,
        ?string $reference = null,
        ?Model $source = null,
        ?string $userId = null,
    ): JournalEntry {
        return DB::transaction(function () use ($date, $lines, $description, $reference, $source, $userId): JournalEntry {
            $entry = $this->draft($date, $lines, $description, $reference, $source, $userId);

            return $this->postDraft($entry, $userId);
        });
    }

    /**
     * Create an entry without posting it.
     *
     * Drafts are working material: they are validated for shape but do not have
     * to balance yet, are excluded from every ledger figure, and consume no
     * number until posted.
     *
     * @param  list<JournalLineData>  $lines
     */
    public function draft(
        DateTimeInterface $date,
        array $lines,
        ?string $description = null,
        ?string $reference = null,
        ?Model $source = null,
        ?string $userId = null,
    ): JournalEntry {
        $date = CarbonImmutable::instance($date)->startOfDay();

        return DB::transaction(function () use ($date, $lines, $description, $reference, $source, $userId): JournalEntry {
            $entry = JournalEntry::create([
                'company_id' => $this->context->idOrFail(),
                // Drafts are unnumbered. Allocating here would consume a number
                // for an entry that may never be posted, leaving a gap.
                'number' => $this->draftPlaceholder(),
                'entry_date' => $date,
                'description' => $description,
                'reference' => $reference,
                'status' => JournalEntryStatus::Draft,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'created_by_id' => $userId,
            ]);

            $this->writeLines($entry, $lines);

            return $this->hydrate($entry);
        });
    }

    /**
     * Post an existing draft.
     */
    public function postDraft(JournalEntry $entry, ?string $userId = null): JournalEntry
    {
        if ($entry->isPosted()) {
            throw PostingRejected::alreadyPosted($entry);
        }

        return DB::transaction(function () use ($entry, $userId): JournalEntry {
            $lines = $entry->lines()->get();

            $this->assertBalanced($lines->all());

            // Resolved at posting time, not at draft time: a draft may sit
            // unposted while its period closes.
            $period = $this->calendar->resolveOpenPeriod($entry->entry_date);

            $totals = $this->totals($lines->all());

            $entry->forceFill([
                'number' => $this->numbers->next(
                    key: 'journal_entry',
                    scope: (string) $entry->entry_date->year,
                    defaults: ['prefix' => 'JE-'.$entry->entry_date->year.'-', 'padding' => 5],
                ),
                'status' => JournalEntryStatus::Posted,
                'fiscal_year_id' => $period->fiscal_year_id,
                'accounting_period_id' => $period->getKey(),
                'total_debit' => $totals['debit'],
                'total_credit' => $totals['credit'],
                'posted_at' => now(),
                'posted_by_id' => $userId,
            ])->save();

            return $this->hydrate($entry);
        });
    }

    /**
     * Reverse a posted entry.
     *
     * Produces a new entry with every movement inverted, in its own series. The
     * predecessor shared one counter between entries and reversals, so each
     * reversal punched a permanent hole in the primary sequence.
     */
    public function reverse(
        JournalEntry $original,
        ?DateTimeInterface $date = null,
        ?string $description = null,
        ?string $userId = null,
    ): JournalEntry {
        if (! $original->isPosted()) {
            throw PostingRejected::cannotReverseDraft($original);
        }

        if ($original->reversedBy()->exists()) {
            throw PostingRejected::alreadyReversed($original);
        }

        $date = CarbonImmutable::instance($date ?? $original->entry_date)->startOfDay();

        return DB::transaction(function () use ($original, $date, $description, $userId): JournalEntry {
            $lines = $original->lines()->with('dimensionAssignments')->get()
                ->map(fn ($line): JournalLineData => (new JournalLineData(
                    accountId: $line->account_id,
                    debit: (string) $line->debit,
                    credit: (string) $line->credit,
                    description: $line->description,
                    currencyId: $line->currency_id,
                    foreignDebit: $line->foreign_debit === null ? null : (string) $line->foreign_debit,
                    foreignCredit: $line->foreign_credit === null ? null : (string) $line->foreign_credit,
                    exchangeRate: $line->exchange_rate === null ? null : (string) $line->exchange_rate,
                    branchId: $line->branch_id,
                    dimensions: $line->dimensionAssignments
                        ->mapWithKeys(fn ($a): array => [$a->dimension_id => $a->dimension_value_id])
                        ->all(),
                ))->inverted())
                ->all();

            $period = $this->calendar->resolveOpenPeriod($date);
            $totals = $this->totals($lines);

            $reversal = JournalEntry::create([
                'company_id' => $this->context->idOrFail(),
                'number' => $this->draftPlaceholder(),
                'entry_date' => $date,
                'description' => $description ?? __('accounting.reversal_of', ['number' => $original->number]),
                'reference' => $original->reference,
                'status' => JournalEntryStatus::Draft,
                'reverses_id' => $original->getKey(),
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                'created_by_id' => $userId,
            ]);

            $this->writeLines($reversal, $lines);

            $reversal->forceFill([
                'number' => $this->numbers->next(
                    key: 'journal_reversal',
                    scope: (string) $date->year,
                    defaults: ['prefix' => 'REV-'.$date->year.'-', 'padding' => 5],
                ),
                'status' => JournalEntryStatus::Posted,
                'fiscal_year_id' => $period->fiscal_year_id,
                'accounting_period_id' => $period->getKey(),
                'total_debit' => $totals['debit'],
                'total_credit' => $totals['credit'],
                'posted_at' => now(),
                'posted_by_id' => $userId,
            ])->save();

            return $this->hydrate($reversal);
        });
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function writeLines(JournalEntry $entry, array $lines): void
    {
        if (count($lines) < 2) {
            throw PostingRejected::tooFewLines();
        }

        $accounts = $this->resolveAccounts($lines);
        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;

            $this->assertLineShape($line, $lineNumber);

            $account = $accounts[$line->accountId] ?? throw PostingRejected::accountNotFound($line->accountId);

            if (! $account->acceptsPostings()) {
                throw PostingRejected::accountNotPostable($account);
            }

            $created = $entry->lines()->create([
                'company_id' => $entry->company_id,
                'account_id' => $account->getKey(),
                'line_number' => $lineNumber,
                'description' => $line->description,
                'debit' => $this->normalise($line->debit),
                'credit' => $this->normalise($line->credit),
                'currency_id' => $line->currencyId,
                'foreign_debit' => $line->foreignDebit,
                'foreign_credit' => $line->foreignCredit,
                'exchange_rate' => $line->exchangeRate,
                'branch_id' => $line->branchId,
            ]);

            $this->dimensions->assign($created, $line->dimensions, $lineNumber);
        }

        $totals = $this->totals($lines);

        $entry->forceFill([
            'total_debit' => $totals['debit'],
            'total_credit' => $totals['credit'],
        ])->save();
    }

    /**
     * Loaded in one query and keyed by id.
     *
     * Resolving each line's account individually would be an N+1 on the hottest
     * path in the system. The company scope applies, so an account belonging to
     * another company simply is not found.
     *
     * @param  list<JournalLineData>  $lines
     * @return array<string, Account>
     */
    private function resolveAccounts(array $lines): array
    {
        $ids = array_unique(array_map(static fn (JournalLineData $l): string => $l->accountId, $lines));

        return Account::query()->whereKey($ids)->get()->keyBy('id')->all();
    }

    private function assertLineShape(JournalLineData $line, int $lineNumber): void
    {
        $debit = $this->normalise($line->debit);
        $credit = $this->normalise($line->credit);

        if (bccomp($debit, '0', self::SCALE) < 0 || bccomp($credit, '0', self::SCALE) < 0) {
            throw PostingRejected::negativeAmount($lineNumber);
        }

        $hasDebit = bccomp($debit, '0', self::SCALE) > 0;
        $hasCredit = bccomp($credit, '0', self::SCALE) > 0;

        if ($hasDebit && $hasCredit) {
            throw PostingRejected::lineHasBothSides($lineNumber);
        }

        if (! $hasDebit && ! $hasCredit) {
            throw PostingRejected::lineHasNoAmount($lineNumber);
        }
    }

    /**
     * @param  iterable<JournalLineData|\App\Models\JournalEntryLine>  $lines
     */
    private function assertBalanced(iterable $lines): void
    {
        $totals = $this->totals($lines);

        if (bccomp($totals['debit'], $totals['credit'], self::SCALE) !== 0) {
            throw PostingRejected::unbalanced($totals['debit'], $totals['credit']);
        }
    }

    /**
     * @param  iterable<JournalLineData|\App\Models\JournalEntryLine>  $lines
     * @return array{debit: string, credit: string}
     */
    private function totals(iterable $lines): array
    {
        $debit = '0';
        $credit = '0';

        foreach ($lines as $line) {
            $debit = bcadd($debit, $this->normalise((string) $line->debit), self::SCALE);
            $credit = bcadd($credit, $this->normalise((string) $line->credit), self::SCALE);
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    private function normalise(string $amount): string
    {
        return bcadd($amount === '' ? '0' : $amount, '0', self::SCALE);
    }

    /**
     * Placeholder occupying the unique index while an entry is a draft.
     *
     * Drafts must not consume real numbers, but `number` is unique and not
     * nullable — a nullable column would let MySQL accept unlimited nulls and
     * quietly lose the constraint that matters once posted.
     */
/**
     * Reload an entry with everything the caller is likely to read.
     *
     * The dimension tags are part of what was just written, so returning the
     * entry without them would make any caller that reads them trigger a query
     * per line — an N+1 on the hottest path in the system.
     */
    private function hydrate(JournalEntry $entry): JournalEntry
    {
        return $entry->fresh(['lines', 'lines.dimensionAssignments']) ?? $entry;
    }

    private function draftPlaceholder(): string
    {
        return 'DRAFT-'.strtoupper(\Illuminate\Support\Str::ulid()->toBase32());
    }
}
