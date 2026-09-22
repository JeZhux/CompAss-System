<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/student/explanations/{id}/explain-further (ARCH-002 FR-027, ARCH-002 FR-029, ARCH-002 FR-029).
 *
 * @Traced-To ARCH-002 FR-027, ARCH-002 FR-029, ARCH-002 FR-029, ARCH-002 QA-002 (ARCH-005 block 4.7)
 */
class ExplainFurtherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer'],
        ];
    }
}
