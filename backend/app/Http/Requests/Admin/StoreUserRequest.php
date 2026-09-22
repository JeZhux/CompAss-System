<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/users (ARCH-005 block 4.9 / ARCH-002 FR-002, ARCH-002 FR-003).
 *
 * CompAss ID only: the admin supplies {name, role}; the server generates the
 * CompAss ID (<ROLE>-<4 digits>-<5 digits>). Manual school_id, legacy email /
 * identifier fields, and placement-metadata keys are prohibited with a
 * deterministic 422 (never silently ignored).
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['Admin', 'Teacher', 'Student'])],
            // Contract is {name, role} only: every other key fails loudly
            // (never silently ignored) so typos and stale clients surface.
            'id' => ['prohibited'],
            'school_id' => ['prohibited'],
            'email' => ['prohibited'],
            'identifier' => ['prohibited'],
            'identifierType' => ['prohibited'],
            'learner_code' => ['prohibited'],
            'group' => ['prohibited'],
            'group_assignment' => ['prohibited'],
            'year_level' => ['prohibited'],
            'password' => ['prohibited'],
            'password_hash' => ['prohibited'],
            'is_active' => ['prohibited'],
            'status' => ['prohibited'],
            'must_change_password' => ['prohibited'],
            'full_name' => ['prohibited'],
            'classroom_id' => ['prohibited'],
        ];
    }

    /**
     * Reject any key outside the {name, role} contract — including typos
     * (e.g. `nmae`, `classroomId`) that no `prohibited` rule names — with a
     * deterministic 422 so mistyped payloads fail loudly instead of being
     * silently ignored. `_method` (method spoofing) is exempt.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $known = array_keys($this->rules());
            foreach (array_keys($this->all()) as $key) {
                if ($key === '_method' || in_array($key, $known, true)) {
                    continue;
                }
                $validator->errors()->add($key, 'This field is not allowed.');
            }
        });
    }
}
