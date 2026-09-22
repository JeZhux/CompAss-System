<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/teacher/submissions/{id}/score (ARCH-002 FR-018).
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 QA-004 (ARCH-005 block 4.4)
 */
class ScoreSubjectiveItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scores' => ['required', 'array'],
            'scores.*' => ['numeric', 'min:0'],
        ];
    }
}
