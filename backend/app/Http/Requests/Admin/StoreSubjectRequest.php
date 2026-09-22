<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/subjects. Subject is scoped under a Grade Level
 * (School Year > Semester > Grade Level > Subject > Competencies);
 * `code` is unique per Grade Level, not globally.
 *
 * @Traced-To ARCH-004 §10 (ARCH-005 block 4.9)
 */
class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('subjects', 'code')->where(
                    fn ($query) => $query->where('grade_level_id', $this->input('grade_level_id'))
                ),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'grade_level_id' => ['required', 'integer', 'min:1', 'exists:grade_levels,id'],
        ];
    }
}
