<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/student/assessments/{id}/attempts/{attemptId}/response (ARCH-002 FR-017).
 *
 * @Traced-To ARCH-002 FR-017 (ARCH-005 block 4.4)
 */
class UpdateAttemptResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'questionId' => ['required', 'integer', 'min:1'],
            'response' => ['required', 'string', 'max:10000'],
        ];
    }
}
