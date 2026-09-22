<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/teacher/assessments/{id}/items (ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015).
 *
 * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-018, ARCH-002 FR-016 (ARCH-005 block 4.12)
 */
class StoreAssessmentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_type' => ['required', Rule::in(['multiple_choice', 'true_false', 'essay'])],
            'prompt' => ['required', 'string', 'max:5000'],
            'max_points' => ['required', 'numeric', 'min:0.01'],
            'correct_answer' => ['required_if:item_type,multiple_choice,true_false', 'string', 'max:1000'],
            'competency_tag_id' => ['required', 'integer', 'exists:competency_reference,id'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'attachments.*' => ['file', 'mimes:pdf,docx,pptx,xlsx,jpg,jpeg,png,zip', 'max:15360'],
        ];
    }
}
