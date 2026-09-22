<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/teacher/submissions/{id}/feedback (ARCH-002 FR-014).
 *
 * @Traced-To ARCH-002 FR-014, ARCH-002 FR-012 (ARCH-005 block 4.11)
 */
class StoreAssignmentFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feedback' => ['required', 'string', 'max:5000'],
        ];
    }
}
