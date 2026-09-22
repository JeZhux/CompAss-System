<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/teacher/assignments (ARCH-002 FR-012, ARCH-002 FR-012).
 *
 * @Traced-To ARCH-002 FR-012, ARCH-002 FR-012, ARCH-002 QA-009 (ARCH-005 block 4.11)
 */
class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isClassroom = $this->route('classroomId') !== null || $this->route('classroom') !== null;
        return [
            'subject_id' => $isClassroom
                ? ['sometimes', 'nullable', 'integer', 'exists:subjects,id']
                : ['prohibited'],
            // Semester derived server-side; supplied value must match (422 on mismatch).
            'semester_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,id'],
            'term_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['required', 'date'],
            'attachments.*' => ['file', 'mimes:pdf,docx,pptx,xlsx,jpg,jpeg,png,zip', 'max:15360'],
        ];
    }
}
