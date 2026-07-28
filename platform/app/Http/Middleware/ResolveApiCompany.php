<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the company context for token-authenticated API requests.
 *
 * The company is taken from the `X-Company-Id` header, but — and this is the
 * entire point — it is only honoured after membership is verified against
 * `company_user`. The predecessor system read the equivalent header and trusted
 * it outright, which let any authenticated caller read any tenant's ledger.
 *
 * A caller belonging to exactly one company may omit the header.
 */
final class ResolveApiCompany
{
    public function __construct(
        private readonly CompanyContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny('Unauthenticated.', Response::HTTP_UNAUTHORIZED);
        }

        $memberships = $user->companies()
            ->wherePivot('status', CompanyMembershipStatus::Active->value);

        $requestedId = $request->header('X-Company-Id');

        if (blank($requestedId)) {
            $companies = $memberships->get();

            if ($companies->count() !== 1) {
                return $this->deny(
                    'This account belongs to multiple companies. Specify one with the X-Company-Id header.',
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $this->context->set($companies->first());

            return $next($request);
        }

        /** @var Company|null $company */
        $company = $memberships->whereKey($requestedId)->first();

        if ($company === null) {
            // Deliberately indistinguishable from "no such company": a caller
            // must not be able to probe which company identifiers exist.
            return $this->deny('Company not found.', Response::HTTP_NOT_FOUND);
        }

        if (! $company->isActive()) {
            return $this->deny('This company is not active.', Response::HTTP_FORBIDDEN);
        }

        $this->context->set($company);

        return $next($request);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
