<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassroomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'school_year' => ['nullable', 'string', 'max:9', 'regex:/^\d{4}-\d{4}$/'],
            'suffix' => ['nullable', 'string', 'max:50'],
        ];
    }
}
