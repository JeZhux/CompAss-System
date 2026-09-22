<?php

namespace App\Http\Requests\Admin;

use App\Services\AuditLogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/audit-logs (#96) and /api/admin/audit-logs/user/{userId} (#98).
 * All query filters are optional; the {userId} route parameter is merged into
 * the validation data so it is validated alongside the query string.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-005 block 4.8)
 */
class AuditLogQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Include route parameters in validation data so the 'userId' rule
     * validates the {userId} route segment of #98.
     */
    public function validationData(): array
    {
        return array_merge($this->query->all(), $this->route()->parameters());
    }

    public function rules(): array
    {
        return [
            'event_type' => ['nullable', 'string', Rule::in(AuditLogService::EVENT_TYPES)],
            'user_id' => ['nullable', 'integer'],
            'auditable_type' => ['nullable', 'string'],
            'auditable_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'],
            'userId' => ['nullable', 'integer'],
        ];
    }
}
