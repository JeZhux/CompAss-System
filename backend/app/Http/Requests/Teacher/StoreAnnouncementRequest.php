<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/teacher/announcements (ARCH-002 FR-011, ARCH-002 FR-011).
 *
 * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 QA-009, ARCH-002 QA-009 (ARCH-005 block 4.10)
 */
class StoreAnnouncementRequest extends FormRequest
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
            // Semester is derived server-side from the classroom; a supplied
            // value must match or it 422s (mismatch guard in service).
            'semester_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,id'],
            'term_id' => ['sometimes', 'nullable', 'integer', 'exists:semesters,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'attachments.*' => ['file', 'mimes:pdf,docx,pptx,xlsx,jpg,jpeg,png,zip', 'max:15360'],
        ];
    }
}
