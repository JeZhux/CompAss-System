<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/student/assignments/{id}/submit (ARCH-002 FR-013, ARCH-002 FR-013).
 *
 * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 QA-009 (ARCH-005 block 4.11)
 */
class SubmitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files.*' => ['file', 'mimes:pdf,docx,pptx,xlsx,jpg,jpeg,png,zip', 'max:15360'],
        ];
    }
}
