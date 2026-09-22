<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/grades/bulk (#104).
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-019, UC-53 (ARCH-005 block 4.5)
 */
class BulkGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assessment_id' => ['required', 'integer', 'exists:assessments,id'],
            // F-05: bulk/size ceilings — at most 100 students per request and
            // at most 100 grade entries per student. Array `max` counts
            // elements, so oversized payloads fail with 422 VALIDATION_ERROR
            // before the controller writes any grade.
            'grades' => ['required', 'array', 'min:1', 'max:100'],
            'grades.*.student_id' => ['required', 'integer', 'exists:users,id'],
            'grades.*.grade_entries' => ['required', 'array', 'min:1', 'max:100'],
            'grades.*.grade_entries.*.assessment_item_id' => ['required', 'integer', 'exists:assessment_items,id'],
            'grades.*.grade_entries.*.score' => ['required', 'numeric', 'min:0'],
            'grades.*.grade_entries.*.max_score' => ['required', 'numeric', 'min:0'],
            'grades.*.grade_entries.*.feedback' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
