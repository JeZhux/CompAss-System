<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/teacher/assignments/{id} (ARCH-002 FR-013).
 *
 * @Traced-To ARCH-002 FR-013, ARCH-002 QA-007 (ARCH-005 block 4.11)
 */
class UpdateAssignmentRequest extends FormRequest
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
            'due_date' => ['sometimes', 'date'],
        ];
    }
}
