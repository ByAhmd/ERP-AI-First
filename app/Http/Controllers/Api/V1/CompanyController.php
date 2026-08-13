<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Caller identity and company selection.
 *
 * Controllers in this application are transport only: they read the request,
 * delegate, and shape a response. There is no business logic here by design.
 */
final class CompanyController extends Controller
{
    /**
     * The authenticated caller and the companies they may act for.
     *
     * Clients call this on start-up to populate a company switcher and to learn
     * which identifier to send in `X-Company-Id`.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'companies' => CompanyResource::collection(
                    $user->getTenants(filament()->getPanel('admin')),
                )->resolve(),
            ],
        ]);
    }

    /**
     * The company the current request is scoped to.
     */
    public function show(CompanyContext $context): JsonResponse
    {
        return response()->json([
            'data' => (new CompanyResource($context->company()))->resolve(),
        ]);
    }
}
