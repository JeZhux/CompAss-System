<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates teacher-assignment writes. Teacher scope is now derived from
 * Classrooms (teacher_id + subject_id + section_id + school_year).
 * This request validates the classroom-scoped vocabulary.
 */
class StoreTeacherAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'teacher_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
