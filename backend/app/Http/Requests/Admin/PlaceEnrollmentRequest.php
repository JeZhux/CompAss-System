<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/admin/enrollments/place (ARCH-005 block 4.17).
 *
 * Phase B: full_name + classroom_id only. The server auto-generates the
 * STU- CompAss ID — manual IDs and placement metadata are refused with a
 * deterministic 422. Every legacy/ambiguous key (group_assignment and its
 * `group` alias, year_level, learner_code, school_id, email, identifier,
 * identifierType, name) is prohibited so a mistyped payload fails loudly
 * instead of being silently ignored. The record number is assigned by the
 * system, so a client-supplied id is refused.
 */
class PlaceEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['full_name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
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
            'role' => ['prohibited'],
            'password' => ['prohibited'],
            'password_hash' => ['prohibited'],
            'is_active' => ['prohibited'],
            'status' => ['prohibited'],
            'must_change_password' => ['prohibited'],
            'full_name' => ['required', 'string', 'max:255'],
            'classroom_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Reject any key outside the {full_name, classroom_id} contract —
     * including typos (e.g. `classroomId`, `fullname`) that no `prohibited`
     * rule names — with a deterministic 422. `_method` exempt.
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
