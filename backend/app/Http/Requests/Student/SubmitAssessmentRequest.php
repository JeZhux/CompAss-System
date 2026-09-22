<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/student/assessments/{id}/submit (ARCH-002 FR-017).
 *
 * @Traced-To ARCH-002 FR-017, ARCH-002 FR-017 (ARCH-005 block 4.4)
 */
class SubmitAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'responses' => ['sometimes', 'array'],
            'responses.*' => ['string'],
        ];
    }
}
