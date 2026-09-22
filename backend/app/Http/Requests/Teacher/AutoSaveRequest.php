<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET|PUT /api/student/assessments/{id}/auto-save (ARCH-002 QA-007, ARCH-002 QA-007).
 *
 * @Traced-To ARCH-002 QA-007, ARCH-002 QA-007 (ARCH-005 block 4.4)
 */
class AutoSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'responses' => ['required', 'array'],
            'responses.*' => ['string'],
        ];
    }
}
