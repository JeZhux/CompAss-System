<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PUT /api/teacher/items/{id} (ARCH-002 FR-018, ARCH-002 FR-016, ARCH-002 FR-016).
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-016, ARCH-002 FR-016 (ARCH-005 block 4.12)
 */
class UpdateAssessmentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_type' => ['sometimes', Rule::in(['multiple_choice', 'true_false', 'essay'])],
            'prompt' => ['sometimes', 'string', 'max:5000'],
            'max_points' => ['sometimes', 'numeric', 'min:0.01'],
            'correct_answer' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'competency_tag_id' => ['sometimes', 'integer', 'exists:competency_reference,id'],
        ];
    }
}
