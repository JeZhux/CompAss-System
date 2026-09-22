<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/enrollments/{id}/move (ARCH-005 block 4.17).
 *
 * The destination classroom travels in the body; the enrollment route key
 * travels in the path. A client-supplied body id is refused, with the same
 * strictness as PlaceEnrollmentRequest: every legacy/ambiguous key
 * (group_assignment and its `group` alias, year_level, learner_code,
 * school_id, email, identifier, identifierType, name, full_name) is
 * prohibited with a deterministic 422 so mistyped payloads fail loudly
 * instead of being silently ignored.
 */
class MoveEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['prohibited'],
            'learner_code' => ['prohibited'],
            'school_id' => ['prohibited'],
            'group' => ['prohibited'],
            'group_assignment' => ['prohibited'],
            'year_level' => ['prohibited'],
            'email' => ['prohibited'],
            'identifier' => ['prohibited'],
            'identifierType' => ['prohibited'],
            'name' => ['prohibited'],
            'full_name' => ['prohibited'],
            'role' => ['prohibited'],
            'password' => ['prohibited'],
            'password_hash' => ['prohibited'],
            'is_active' => ['prohibited'],
            'status' => ['prohibited'],
            'must_change_password' => ['prohibited'],
            'classroom_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Reject any key outside the {classroom_id} contract — including typos
     * (e.g. `classroomId`, `destination`) that no `prohibited` rule names —
     * with a deterministic 422. `_method` exempt.
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
