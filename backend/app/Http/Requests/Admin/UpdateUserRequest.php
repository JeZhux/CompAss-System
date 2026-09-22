<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /admin/users/{id}. Partial update of name only (school_id is immutable,
 * email is removed).
 *
 * Manual school_id, legacy email / identifier fields, and
 * placement-metadata keys are prohibited with a deterministic 422 (never
 * silently ignored).
 *
 * @Traced-To ARCH-002 FR-004 (ARCH-005 block 4.9)
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Update is name-only (school_id immutable, role managed at
            // creation): every other key fails loudly, never silently ignored.
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'id' => ['prohibited'],
            'role' => ['prohibited'],
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
     * Reject any key outside the {name} contract — including typos
     * (e.g. `nmae`) that no `prohibited` rule names — with a deterministic
     * 422 so mistyped payloads fail loudly. `_method` exempt.
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
