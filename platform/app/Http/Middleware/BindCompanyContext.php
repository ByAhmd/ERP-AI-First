<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
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
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Company) {
            $this->context->set($tenant);

            // Roles and permissions are company-scoped through
            // spatie/laravel-permission's teams feature. Nothing else tells the
            // registrar which company applies, and until it is told it resolves
            // against a null team — so every permission check fails and the
            // panel renders with no navigation. Binding it here, alongside the
            // company context, keeps the two from diverging.
            $this->permissions->setPermissionsTeamId($tenant->getKey());
        }

        return $next($request);
    }
}
