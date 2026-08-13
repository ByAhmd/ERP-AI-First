<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\SystemAccount;
use App\Models\Account;
use App\Services\Accounting\Exceptions\SystemAccountMissing;
use App\Support\Tenancy\CompanyContext;

/**
 * Resolves the accounts the platform posts to by role.
 *
 * Every module that needs a specific account — VAT on an invoice, cost of sales
 * on a shipment, the year-end transfer — asks here rather than looking up a
 * code. The company may renumber and rename its chart without breaking posting.
 *
 * Resolution is cached per request. A single invoice posting resolves the same
 * VAT account for every line, and an inventory movement resolves cost of sales
 * for each item.
 */
final class AccountRegistry
{
    /**
     * @var array<string, Account>
     */
    private array $resolved = [];

    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    /**
     * The account fulfilling a role, or a thrown exception.
     *
     * Fails loudly rather than returning null: a caller that reaches here is
     * about to post, and continuing without the account would either produce an
     * unbalanced entry or quietly book the amount somewhere else.
     */
    public function get(SystemAccount $role): Account
    {
        return $this->find($role) ?? throw SystemAccountMissing::forRole($role);
    }

    public function find(SystemAccount $role): ?Account
    {
        $cacheKey = $this->context->id().':'.$role->value;

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $account = Account::query()->where('system_key', $role->value)->first();

        if ($account === null) {
            return null;
        }

        return $this->resolved[$cacheKey] = $account;
    }

    public function has(SystemAccount $role): bool
    {
        return $this->find($role) !== null;
    }

    /**
     * Roles with no account in the current company.
     *
     * Surfaced during setup so a company is told what is missing before it
     * tries to post, rather than at the moment an invoice fails to approve.
     *
     * @return list<SystemAccount>
     */
    public function missing(): array
    {
        $present = Account::query()
            ->whereNotNull('system_key')
            ->pluck('system_key')
            ->all();

        return array_values(array_filter(
            SystemAccount::cases(),
            static fn (SystemAccount $role): bool => ! in_array($role->value, $present, true),
        ));
    }

    /**
     * Discard cached resolutions.
     *
     * Needed when the company context changes mid-process, as it does in a
     * consolidation run or a queue worker handling several companies.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
