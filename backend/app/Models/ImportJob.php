<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata record for each batch .xlsx import operation (ARCH-002 FR-033/ARCH-002 FR-031/ARCH-002 FR-031).
 *
 * @Traced-To ARCH-002 FR-033, ARCH-002 FR-031, ARCH-002 FR-031, ARCH-002 FR-031, ARCH-004 §8.1 (ARCH-001 §5.1 / ARCH-004 §4.2)
 */
class ImportJob extends Model
{
    protected $fillable = [
        'import_type',
        'admin_id',
        'total_rows',
        'imported_rows',
        'failed_rows',
        'error_report_path',
        'error_report_token',
        'error_report_expires_at',
        'error_report_used_at',
    ];

    protected $casts = [
        'import_type' => 'string',
        'error_report_expires_at' => 'datetime',
        'error_report_used_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
