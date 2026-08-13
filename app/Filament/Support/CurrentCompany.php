<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Company;
use App\Support\Tenancy\Exceptions\CompanyContextMissing;
use Filament\Facades\Filament;

/**
 * Resolves the panel's tenant as a Company.
 *
 * `Filament::getTenant()` is typed as returning `Model|null`, so every call site
 * that passes it to a service expecting a Company was relying on an untyped
 * value being the right class. This narrows it once, in one place, and fails
 * loudly rather than passing null into a service that cannot use it.
 *
 * Panel middleware has already verified membership before any page loads, so a
 * missing tenant here means the caller is running outside the panel, not that
 * the user lacks access.
 */
final class CurrentCompany
{
    /**
     * The company this request is scoped to.
     */
    public static function get(): Company
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            throw CompanyContextMissing::forOperation();
        }

        return $tenant;
    }

    /**
     * The company, or null outside a panel request.
     */
    public static function find(): ?Company
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company ? $tenant : null;
    }

    /**
     * The company's identifier, or null outside a panel request.
     */
    public static function key(): ?string
    {
        return self::find()?->getKey();
    }
}
