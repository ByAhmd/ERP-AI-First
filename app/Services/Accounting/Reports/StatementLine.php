<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * One row of a financial statement.
 *
 * Rows nest, because a chart of accounts does: "Current Assets" is a heading
 * whose figure is the sum of the accounts beneath it, and an accountant reads
 * the total and the detail as one thing. A flat list would force the reader to
 * add up the indented rows themselves and hope they agree with the heading.
 *
 * Amounts are positional — one per statement column, in the same order as the
 * statement's periods. Strings throughout, as everywhere the ledger is
 * involved.
 */
final readonly class StatementLine
{
    /**
     * @param  list<string>  $amounts  One per statement column.
     * @param  list<StatementLine>  $children
     */
    public function __construct(
        public string $name,
        public array $amounts,
        public int $depth = 0,
        public ?string $accountId = null,
        public ?string $code = null,
        public array $children = [],
        public bool $isDerived = false,
        public ?StatementDrillTarget $drill = null,
    ) {}

    /**
     * A row the ledger does not hold an account for.
     *
     * The result carried into equity is the case that matters: no account
     * accumulates it, because nothing posts to retained earnings until the year
     * is closed, yet the balance sheet cannot balance without it. Marking such
     * rows keeps a reader from clicking through to an account that isn't there.
     *
     * @param  list<string>  $amounts
     */
    public static function derived(
        string $name,
        array $amounts,
        int $depth = 0,
        ?StatementDrillTarget $drill = null,
        ?string $accountId = null,
        ?string $code = null,
    ): self {
        return new self(
            name: $name,
            amounts: $amounts,
            depth: $depth,
            accountId: $accountId,
            code: $code,
            isDerived: true,
            drill: $drill,
        );
    }

    public function isDrillable(): bool
    {
        return $this->drill?->isDrillable() ?? false;
    }

    /**
     * Whether this row, or anything beneath it, carries a figure.
     *
     * A chart of accounts is provisioned complete and most companies use a
     * fraction of it; without this a first statement is mostly zeroes.
     */
    public function hasAmount(): bool
    {
        foreach ($this->amounts as $amount) {
            if (bccomp($amount, '0', 4) !== 0) {
                return true;
            }
        }

        foreach ($this->children as $child) {
            if ($child->hasAmount()) {
                return true;
            }
        }

        return false;
    }
}
