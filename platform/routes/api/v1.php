<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CompanyController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Consumed by the offline POS client, mobile applications, and third-party
| integrations. Every route below the auth group is company-scoped: the
| `company` middleware resolves the caller's company and verifies membership
| before any query runs.
|
| Domain endpoints are added by their owning phase; this file holds the
| skeleton and the routes that exist independently of any business module.
|
*/

Route::get('health', fn (): JsonResponse => response()->json([
    'status' => 'ok',
    'version' => 'v1',
]))->name('health');

Route::middleware(['auth:sanctum', 'locale'])->group(function (): void {

    // Identity of the caller, and the companies they may act for. A client
    // calls this first to discover which value to send in X-Company-Id.
    Route::get('me', [CompanyController::class, 'me'])->name('me');

    Route::middleware('company')->group(function (): void {
        Route::get('company', [CompanyController::class, 'show'])->name('company.show');
    });
});
