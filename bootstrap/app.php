<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveApiCompany;
use App\Http\Middleware\SetLocale;
use App\Support\Tenancy\Exceptions\CompanyContextMissing;
use App\Support\Tenancy\Exceptions\CompanyMismatch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Versioned explicitly rather than through a single api.php, so that
            // introducing v2 never risks altering v1's behaviour for the
            // integrations (mobile, Zapier, e-commerce) that depend on it.
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api/v1.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company' => ResolveApiCompany::class,
            'locale' => SetLocale::class,
        ]);

        // Laravel defaults unauthenticated guests to route('login'), which this
        // application does not define — Filament names its own
        // `filament.admin.auth.login`. Left unset, an API client that omits
        // `Accept: application/json` gets a 500 from the missing route instead
        // of a 401. Returning null for API paths suppresses the redirect
        // entirely so the authentication failure is reported as itself.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*')
                ? null
                : route('filament.admin.auth.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A missing or mismatched company is a server-side contract violation,
        // not something a client can correct. Report it, and never surface the
        // underlying message, which names internal classes.
        $exceptions->render(function (CompanyContextMissing|CompanyMismatch $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            report($e);

            return response()->json(
                ['message' => 'The request could not be attributed to a company.'],
                Response::HTTP_CONFLICT,
            );
        });
    })->create();
