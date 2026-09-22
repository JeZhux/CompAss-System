<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/school-years/{schoolYearId}/semesters.
 * Semester is a trimester value ('1', '2', or '3'); dates validated per ARCH-002 FR-020.
 *
 * @Traced-To ARCH-002 FR-004 (ARCH-005 block 4.9)
 */
class StoreSemesterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'semester' => ['required', Rule::in(['1', '2', '3', 1, 2, 3])],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}
