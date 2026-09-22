<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit-trail record (ARCH-002 QA-006, ARCH-002 QA-004; ARCH-001 §5.1, ARCH-004 §4.1).
 *
 * Maps to the `audit_logs` table. Rows are inserted exclusively via
 * AuditLogService::log() and are never updated or deleted by application logic.
 * The only permitted mutations are the scheduled IP-anonymization and
 * retention-purge jobs in AuditLogService (ARCH-004 §8.1).
 *
 * SoftDeletes is intentionally NOT used, so newQuery() applies no soft-delete
 * filtering — reads always see every row. UPDATED_AT is disabled (the table
 * has a created_at column but no updated_at column), so Eloquent never emits an
 * UPDATE to manage a timestamp; only created_at is auto-stamped on insert.
 */
class AuditLog extends Model
{
    // All attributes are mass-assignable because AuditLogService is the sole
    // writer of this table (ARCH-002 QA-006).
    protected $guarded = [];

    // The audit_logs table has created_at but no updated_at column; disabling
    // update-timestamp management keeps Eloquent insert-only (append-only, ARCH-002 QA-006).
    public const UPDATED_AT = null;

    /**
     * Attribute casts. `event_type` is a native PostgreSQL enum
     * (audit_event_type); it is read back as a string and kept as a string.
     * `metadata` is JSONB on disk and round-trips as an array.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_type' => 'string',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * The actor (user) who triggered the audit entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
