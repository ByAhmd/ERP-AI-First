<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\Exceptions\CompanyContextMissing;
use Closure;

/**
 * Holds the company whose data the current execution context may access.
 *
 * Every tenant-owned query is filtered by this value through {@see CompanyScope}.
 * It is resolved from the Filament panel tenant for panel requests, from the
 * authenticated token for API requests, and set explicitly by queued jobs and
 * console commands, which have no session to infer it from.
 *
 * The context is deliberately *not* readable from request input. The predecessor
 * system accepted an `x-tenant-id` header and trusted it, which allowed any
 * authenticated user to read another company's ledger.
 */
final class CompanyContext
{
    private ?string $companyId = null;

    private ?Company $company = null;

    private bool $bypassed = false;

    /**
     * Bind the context to a company for the remainder of the request, job or command.
     */
    public function set(Company|string $company): void
    {
        if ($company instanceof Company) {
            $this->company = $company;
            $this->companyId = $company->getKey();

            return;
        }

        // Resolved lazily: callers frequently only need the identifier.
        $this->company = null;
        $this->companyId = $company;
    }

    public function id(): ?string
    {
        return $this->companyId;
    }

    public function company(): ?Company
    {
        if ($this->company !== null) {
            return $this->company;
        }

        if ($this->companyId === null) {
            return null;
        }

        return $this->company = Company::withoutGlobalScopes()->find($this->companyId);
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    /**
     * The identifier, or a thrown exception when absent.
     *
     * Used by call sites that cannot meaningfully continue without a company and
     * would otherwise silently write orphaned rows.
     */
    public function idOrFail(): string
    {
        return $this->companyId ?? throw CompanyContextMissing::forOperation();
    }

    /**
     * Run a callback with company filtering disabled.
     *
     * Reserved for genuinely cross-company work: platform administration,
     * consolidation across a group, and migrations. It is an explicit, greppable
     * call rather than an ambient flag, so every use can be reviewed.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScoping(Closure $callback): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }

    /**
     * Run a callback as a specific company, restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function forCompany(Company|string $company, Closure $callback): mixed
    {
        $previousId = $this->companyId;
        $previousCompany = $this->company;

        $this->set($company);

        try {
            return $callback();
        } finally {
            $this->companyId = $previousId;
            $this->company = $previousCompany;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * Clear the context. Queue workers call this between jobs so that one job's
     * company can never leak into the next.
     */
    public function forget(): void
    {
        $this->companyId = null;
        $this->company = null;
        $this->bypassed = false;
    }
}
