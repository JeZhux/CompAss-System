<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/grades/manual (#100).
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-019 (ARCH-005 block 4.5)
 */
class StoreManualGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attempt_id' => ['required', 'integer', 'exists:assessment_attempts,id'],
            'grade_entries' => ['required', 'array', 'min:1'],
            'grade_entries.*.assessment_item_id' => ['required', 'integer', 'exists:assessment_items,id'],
            'grade_entries.*.score' => ['required', 'numeric', 'min:0'],
            'grade_entries.*.max_score' => ['required', 'numeric', 'min:0'],
            'grade_entries.*.feedback' => ['nullable', 'string', 'max:5000'],
            'is_draft' => ['nullable', 'boolean'],
        ];
    }
}
