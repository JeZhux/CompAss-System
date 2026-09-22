<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates POST /api/auth/change-password (ARCH-005 block 4.1 / ARCH-002 FR-003).
 *
 * This is the only endpoint exempt from the forced-password gate.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required', 'string', 'different:current_password', Password::min(8)->mixedCase()->numbers(),
            ],
        ];
    }
}
