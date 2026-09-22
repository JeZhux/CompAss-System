<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/import/student-enrollments/*,
 * POST /api/admin/import/teacher-applications/*, and
 * POST /api/admin/import/competency-tags.
 * Validates that the uploaded file is an .xlsx of acceptable size
 * (row-count ceilings per ARCH-003 ADR-005, ARCH-002 QA-009).
 *
 * Bulk sheets are full_name-only; IDs are server-generated.
 *
 * @Traced-To ARCH-002 FR-033, ARCH-002 FR-031, ARCH-003 ADR-005, ARCH-002 QA-009 (ARCH-005 block 4.6)
 */
class ImportXlsxRequest extends FormRequest
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
                'mimes:xlsx,xls',
                'max:15360',
            ],
        ];
    }
}
