<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * One contact's line on a balances summary report — ملخص المستحقات.
 *
 * Open documents, less the standalone notes and unused vouchers held against
 * the same contact, netting to what is actually still owed either way.
 */
final readonly class BalancesSummaryRow
{
    public function __construct(
        public string $contactId,
        public string $name,
        public ?string $code,
        public string $openInvoices,
        public string $unappliedNotes,
        public string $unusedVouchers,
        public string $net,
    ) {}
}
