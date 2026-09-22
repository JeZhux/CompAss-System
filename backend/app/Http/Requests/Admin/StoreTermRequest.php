<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/school-years/{schoolYearId}/terms (deprecated alias of the
 * Semester endpoint). `semester` is REQUIRED here, same as the canonical
 * /semesters route — legacy name-parsing defaults were removed to close the
 * spoof where POST /terms without a semester silently derived one from the
 * display name. New clients should use StoreSemesterRequest via /semesters.
 * Dates validated per ARCH-002 FR-020.
 *
 * @deprecated Use StoreSemesterRequest instead.
 *
 * @Traced-To ARCH-002 FR-004 (ARCH-005 block 4.9)
 */
class StoreTermRequest extends FormRequest
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
