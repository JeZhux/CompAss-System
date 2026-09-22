<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staged retention for full_name-only bulk imports (Phase B).
 *
 * Sheets carry a single `full_name` column; the server auto-generates a fresh
 * CompAss ID per row (STU- for students, TEA- for teachers). The
 * `learner_code` column stores that generated school_id for audit continuity
 * ('' when the row failed before generation); the parent import_job's
 * import_type distinguishes student vs teacher bulk. Canonical tables
 * (users, classroom_enrollments, classrooms) gain NO columns.
 */
class EnrollmentImportRow extends Model
{
    protected $fillable = [
        'import_job_id',
        'row_number',
        'learner_code',
        'full_name',
        'error',
    ];

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }
}
