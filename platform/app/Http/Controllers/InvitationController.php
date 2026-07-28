<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Services\Identity\Exceptions\InvitationFailed;
use App\Services\Identity\InvitationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Invitation acceptance.
 *
 * Public by necessity — the recipient has no account yet. The token is the only
 * credential, which is why it is single-use, hashed at rest, and time-limited.
 */
final class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
    ) {}

    public function show(string $token): View
    {
        $membership = $this->invitations->findByToken($token);

        if ($membership === null) {
            return view('invitations.invalid');
        }

        return view('invitations.accept', [
            'token' => $token,
            'company' => $membership->company,
            'user' => $membership->user,
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        try {
            $this->invitations->accept($token, $request->validated('password'));
        } catch (InvitationFailed $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        return redirect()
            ->route('filament.admin.auth.login')
            ->with('status', __('identity.invitations.accept.success'));
    }
}
