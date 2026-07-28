<?php

declare(strict_types=1);

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('filament.admin.auth.login'));

/*
|--------------------------------------------------------------------------
| Invitations
|--------------------------------------------------------------------------
|
| Public: the recipient has no account until they accept. The token is the
| credential, so these routes are rate limited to blunt enumeration despite the
| token being 256 bits of entropy.
|
*/

Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->name('invitations.show');

    Route::post('invitations/{token}', [InvitationController::class, 'store'])
        ->name('invitations.accept');
});
