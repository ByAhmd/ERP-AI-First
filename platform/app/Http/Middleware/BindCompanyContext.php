<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the Filament panel's resolved tenant to the application's company context.
 *
 * Filament has already verified membership via {@see \App\Models\User::canAccessTenant()}
 * before this runs, so the company reaching the context is always one the
 * authenticated user belongs to. Nothing here reads request input.
 */
final class BindCompanyContext
{
    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Company) {
            $this->context->set($tenant);
        }

        return $next($request);
    }
}
