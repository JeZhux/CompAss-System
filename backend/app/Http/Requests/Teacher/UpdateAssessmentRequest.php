<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/teacher/assessments/{id} (ARCH-002 FR-016).
 *
 * @Traced-To ARCH-002 FR-016, ARCH-002 FR-016, ARCH-002 FR-016 (ARCH-005 block 4.12)
 */
class UpdateAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
