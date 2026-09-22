<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/competency-tags (Competency catalog read).
 * All query filters are optional; invalid shapes yield 422 VALIDATION_ERROR
 * via the shared exception envelope. Digits-only ?search= is rejected in
 * the controller via HumanSearch (same as admin user reads).
 *
 * @Traced-To ARCH-002 FR-031 (ARCH-005 block 4.6)
 */
class ListCompetencyTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Server owns the min-2 search contract (frontend guards are
        // bypassable direct-API). per_page intentionally carries no min/max
        // rules: per the REM-036 convention (AuditLogQueryRequest, users
        // index) out-of-range per_page clamps via Pagination::perPage
        // (1..100 backstop) instead of 422ing, so other clients keep working.
        return [
            'search' => ['nullable', 'string', 'min:2', 'max:255'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'grade_level' => ['nullable', Rule::in(['7', '8', '9', '10', '11', '12', 7, 8, 9, 10, 11, 12])],
            'semester' => ['nullable', Rule::in(['1', '2', '3', 1, 2, 3])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'],
        ];
    }
}
