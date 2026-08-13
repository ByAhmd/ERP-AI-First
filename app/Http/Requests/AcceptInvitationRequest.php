<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AcceptInvitationRequest extends FormRequest
{
    /**
     * The token in the route is the authorisation; it is verified by the
     * service, which is the only thing that can meaningfully validate it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'confirmed',
                Password::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    // Rejects passwords known to have appeared in breaches.
                    // This account will have access to financial records.
                    ->uncompromised(),
            ],
        ];
    }
}
