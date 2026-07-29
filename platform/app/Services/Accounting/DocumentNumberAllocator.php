<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\DocumentSequence;
use App\Services\Accounting\Exceptions\SequenceAllocationFailed;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Allocates gapless document numbers.
 *
 * Correctness rests on two rules, both enforced rather than documented:
 *
 *  1. Allocation happens inside the caller's transaction. If the document fails
 *     to save, the counter rolls back with it and the number is not consumed.
 *  2. The counter row is locked for update, so concurrent callers serialise on
 *     that row alone and cannot receive the same number.
 *
 * Calling this outside a transaction is refused. Doing so would allocate a
 * number that survives a failed insert, which is precisely how the predecessor
 * left permanent gaps in a series ZATCA requires to be unbroken.
 */
final class DocumentNumberAllocator
{
    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * Take the next number in a series.
     *
     * @param  string  $key  Document type, e.g. `journal_entry`.
     * @param  string|null  $scope  Reset scope, e.g. a year. Null runs continuously.
     * @param  array{prefix?: string, suffix?: string, padding?: int, next_number?: int}  $defaults
     *         Applied only when the series is created.
     */
    public function next(string $key, ?string $scope = null, array $defaults = []): string
    {
        if (DB::transactionLevel() === 0) {
            throw SequenceAllocationFailed::outsideTransaction($key);
        }

        $companyId = $this->context->idOrFail();
        $scope ??= '';

        $sequence = $this->lockOrCreate($companyId, $key, $scope, $defaults);

        $number = $sequence->format($sequence->next_number);

        // Written through the query builder rather than the model so the update
        // cannot be reordered behind other model events.
        DocumentSequence::query()
            ->whereKey($sequence->getKey())
            ->update(['next_number' => $sequence->next_number + 1]);

        return $number;
    }

    /**
     * Peek at the next number without consuming it.
     *
     * For display only. The value is advisory: a concurrent allocation may take
     * it before the caller does.
     */
    public function preview(string $key, ?string $scope = null): ?string
    {
        $sequence = DocumentSequence::query()
            ->where('key', $key)
            ->where('scope', $scope ?? '')
            ->first();

        return $sequence?->format($sequence->next_number);
    }

    /**
     * @param  array{prefix?: string, suffix?: string, padding?: int, next_number?: int}  $defaults
     */
    private function lockOrCreate(
        string $companyId,
        string $key,
        string $scope,
        array $defaults,
    ): DocumentSequence {
        $sequence = DocumentSequence::query()
            ->where('key', $key)
            ->where('scope', $scope)
            ->lockForUpdate()
            ->first();

        if ($sequence !== null) {
            return $sequence;
        }

        // Two concurrent first-allocations can both miss above; the unique
        // index on (company_id, key, scope) decides between them, and the loser
        // re-reads the winner's row rather than failing the caller's document.
        try {
            return DocumentSequence::query()->create([
                'company_id' => $companyId,
                'key' => $key,
                'scope' => $scope,
                'prefix' => $defaults['prefix'] ?? '',
                'suffix' => $defaults['suffix'] ?? '',
                'padding' => $defaults['padding'] ?? 4,
                'next_number' => $defaults['next_number'] ?? 1,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return DocumentSequence::query()
                ->where('key', $key)
                ->where('scope', $scope)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }
}
