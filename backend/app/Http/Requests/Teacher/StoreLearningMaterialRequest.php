<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/teacher/learning-materials (ARCH-002 FR-026).
 *
 * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 QA-009, ARCH-002 QA-010 (ARCH-005 block 4.7)
 */
class StoreLearningMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'competency_id' => ['required', 'integer', 'exists:competency_reference,id'],
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,docx', 'max:15360'],
        ];
    }
}
