<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/teacher/announcements/{id} (ARCH-002 FR-011).
 *
 * @Traced-To ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-005 block 4.10)
 */
class UpdateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:5000'],
            'attachments.*' => ['file', 'mimes:pdf,docx,pptx,xlsx,jpg,jpeg,png,zip', 'max:15360'],
        ];
    }
}
