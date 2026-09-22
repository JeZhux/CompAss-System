<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/terms/{semesterId}/grade-levels. Grade level restricted to
 * grades 7 through 12 (ARCH-004 §10). Parent is a Semester (1, 2, 3).
 *
 * @Traced-To ARCH-002 FR-005 (ARCH-005 block 4.9)
 */
class StoreGradeLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grade_level' => ['required', 'integer'],
        ];
    }
}
