<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-progress assessment response state, auto-saved every 60s (ARCH-002 QA-007).
 * Per-attempt rows (ARCH-002 FR-017): UNIQUE(student_id, assessment_id, attempt_id);
 * attempt_id is nullable for legacy rows.
 *
 * @Traced-To ARCH-002 QA-007, ARCH-002 QA-007 (ARCH-004 §4.1 Assessment Auto Saves), ARCH-002 FR-017
 */
class AssessmentAutoSave extends Model
{
    protected $fillable = [
        'student_id',
        'assessment_id',
        'attempt_id',
        'responses',
    ];

    public $timestamps = false;

    protected $casts = [
        'responses' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'attempt_id');
    }
}
