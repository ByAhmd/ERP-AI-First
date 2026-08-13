<?php

declare(strict_types=1);

namespace App\Services\Accounting\Data;

/**
 * One proposed movement, before it becomes a ledger line.
 *
 * Amounts travel as strings. Passing them as floats would round them before the
 * ledger ever saw them, and a rounding error in a posting engine is a
 * permanently unbalanced set of books.
 */
final readonly class JournalLineData
{
    public function __construct(
        public string $accountId,
        public string $debit = '0',
        public string $credit = '0',
        public ?string $description = null,
        public ?string $currencyId = null,
        public ?string $foreignDebit = null,
        public ?string $foreignCredit = null,
        public ?string $exchangeRate = null,
        public ?string $branchId = null,
        /**
         * Dimension value assignments, keyed by dimension id.
         *
         * An array rather than named fields because the set of dimensions is
         * defined by the company and unknowable at compile time.
         *
         * @var array<string, string>
         */
        public array $dimensions = [],
    ) {}

    public static function debit(string $accountId, string $amount, ?string $description = null): self
    {
        return new self(accountId: $accountId, debit: $amount, description: $description);
    }

    public static function credit(string $accountId, string $amount, ?string $description = null): self
    {
        return new self(accountId: $accountId, credit: $amount, description: $description);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accountId: (string) $data['account_id'],
            debit: (string) ($data['debit'] ?? '0'),
            credit: (string) ($data['credit'] ?? '0'),
            description: $data['description'] ?? null,
            currencyId: $data['currency_id'] ?? null,
            foreignDebit: isset($data['foreign_debit']) ? (string) $data['foreign_debit'] : null,
            foreignCredit: isset($data['foreign_credit']) ? (string) $data['foreign_credit'] : null,
            exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
            branchId: $data['branch_id'] ?? null,
            dimensions: $data['dimensions'] ?? [],
        );
    }

    /**
     * The same movement with its sides exchanged, for reversal.
     */
    public function inverted(): self
    {
        return new self(
            accountId: $this->accountId,
            debit: $this->credit,
            credit: $this->debit,
            description: $this->description,
            currencyId: $this->currencyId,
            foreignDebit: $this->foreignCredit,
            foreignCredit: $this->foreignDebit,
            exchangeRate: $this->exchangeRate,
            branchId: $this->branchId,
            dimensions: $this->dimensions,
        );
    }
}
