<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/import/students/csv.
 * Validates that the uploaded file is a .csv of acceptable size
 * (ARCH-002 FR-033, row-count ceilings per ARCH-003 ADR-005).
 *
 * @Traced-To ARCH-002 FR-033, ARCH-003 ADR-005, ARCH-003 ADR-008 (ARCH-005 block 4.6)
 */
class ImportStudentsCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:15360',
            ],
        ];
    }
}
