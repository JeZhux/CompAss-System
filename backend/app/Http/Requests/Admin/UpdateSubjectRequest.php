<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /admin/subjects/{id}. Fields are optional per the spec (partial update);
 * code uniqueness is scoped per Grade Level under edit.
 *
 * @Traced-To ARCH-004 §10 (ARCH-005 block 4.9)
 */
class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $subjectId = (int) $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('subjects', 'code')
                    ->ignore($subjectId)
                    ->where(fn ($query) => $query->where(
                        'grade_level_id',
                        $this->input('grade_level_id', $this->resolveCurrentGradeLevelId($subjectId))
                    )),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'grade_level_id' => ['sometimes', 'required', 'integer', 'min:1', 'exists:grade_levels,id'],
        ];
    }

    private function resolveCurrentGradeLevelId(int $subjectId): ?int
    {
        try {
            return \App\Models\Subject::where('id', $subjectId)->value('grade_level_id');
        } catch (\Throwable) {
            return null;
        }
    }
}
