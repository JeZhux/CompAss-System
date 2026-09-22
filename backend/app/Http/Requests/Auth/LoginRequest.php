<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/auth/login (ARCH-005 block 4.1).
 *
 * CompAss ID only: the single `identifier` field carries the caller's
 * school_id (<ROLE>-<4 digits>-<5 digits>) for all roles.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
