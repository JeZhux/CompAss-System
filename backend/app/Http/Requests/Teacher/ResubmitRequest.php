<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit (#103).
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, UC-52 (ARCH-005 block 4.5)
 */
class ResubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}
