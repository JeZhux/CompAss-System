<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/import/templates/{type}.
 * Validates the template type path parameter.
 *
 * @Traced-To ARCH-002 FR-031 (ARCH-005 block 4.6)
 */
class GetImportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Include route parameters in validation data so the 'type' rule
     * above validates the {type} route segment.
     */
    public function validationData(): array
    {
        return array_merge($this->query->all(), $this->route()->parameters());
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['competency_tags', 'student_enrollment', 'student_enrollments', 'student-enrollments', 'teacher_application', 'teacher_applications', 'teacher-applications'])],
        ];
    }
}
