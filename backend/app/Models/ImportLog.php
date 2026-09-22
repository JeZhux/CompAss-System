<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log for completed synchronous CSV import operations (ARCH-002 FR-033).
 *
 * @Traced-To ARCH-002 FR-033, ARCH-003 ADR-008 (ARCH-001 §5.1 / ARCH-004 §4.2)
 */
class ImportLog extends Model
{
    protected $fillable = [
        'admin_id',
        'filename',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'imported_rows',
        'skipped_rows',
        'status',
        'error_details',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'error_details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
