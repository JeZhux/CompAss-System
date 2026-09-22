<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/teacher/learning-materials/{id} (ARCH-002 FR-026).
 *
 * @Traced-To ARCH-002 FR-026, UC-46 (ARCH-005 block 4.7)
 */
class UpdateLearningMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'file' => ['sometimes', 'file', 'mimes:pdf,docx', 'max:15360'],
        ];
    }
}
