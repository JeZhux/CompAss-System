<?php

namespace App\Http\Requests\Admin;

/**
 * GET /api/admin/audit-logs/entity (#97).
 * Extends AuditLogQueryRequest with REQUIRED auditable_type / auditable_id —
 * missing values surface as the standard 422 VALIDATION_ERROR envelope with
 * `fields` via the global renderer.
 *
 * @Traced-To ARCH-002 QA-006 (ARCH-005 block 4.8)
 */
class AuditLogEntityRequest extends AuditLogQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'auditable_type' => ['required', 'string'],
            'auditable_id' => ['required', 'integer'],
        ]);
    }
}
