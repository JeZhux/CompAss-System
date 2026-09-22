<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/school-years. Validated against ARCH-004 §4.1 (`school_years.name` UNIQUE).
 *
 * @Traced-To ARCH-002 FR-005 (ARCH-005 block 4.9)
 */
class StoreSchoolYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:school_years,name'],
        ];
    }
}
