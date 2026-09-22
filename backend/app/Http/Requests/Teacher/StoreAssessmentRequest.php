<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/teacher/assessments (ARCH-002 FR-015, ARCH-002 FR-015).
 *
 * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015 (ARCH-005 block 4.12)
 */
class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isClassroom = $this->route('classroomId') !== null || $this->route('classroom') !== null;
        return [
            'subject_id' => $isClassroom
                ? ['sometimes', 'nullable', 'integer', 'exists:subjects,id']
                : ['prohibited'],
            // Semester derived server-side from the classroom; supplied value
            // must match or it 422s (mismatch guard in service).
            'semester_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,id'],
            'term_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['Recorded', 'Unrecorded'])],
            'time_limit' => ['nullable', 'integer', 'min:1'],
            'availability_starts_at' => ['nullable', 'date'],
            'availability_ends_at' => ['nullable', 'date', 'after:availability_starts_at'],
        ];
    }
}
