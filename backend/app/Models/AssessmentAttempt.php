<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Attempt-level container for assessment attempts (ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-018).
 * Lifecycle: in_progress -> submitted -> pending_grading -> scored.
 * Attempt lifecycle extras: started_at (ARCH-004 §4.1) and resubmission state
 * (ARCH-002 FR-018): is_resubmission, resubmission_of_attempt_id,
 * resubmission_reason, resubmission_requested_at.
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-018 (ARCH-004 §4.1 Assessment Attempts),
 *            ARCH-004 §4.1, ARCH-002 FR-018
 */
class AssessmentAttempt extends Model
{
    protected $fillable = [
        'assessment_id',
        'student_id',
        'attempt_number',
        'status',
        'grader_id',
        'graded_at',
        'response_history',
        'started_at',
        'is_resubmission',
        'resubmission_of_attempt_id',
        'resubmission_reason',
        'resubmission_requested_at',
    ];

    protected $casts = [
        'status' => 'string',
        'graded_at' => 'datetime',
        'response_history' => 'array',
        'started_at' => 'datetime',
        'is_resubmission' => 'boolean',
        'resubmission_of_attempt_id' => 'integer',
        'resubmission_requested_at' => 'datetime',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grader_id');
    }

    public function resubmissionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resubmission_of_attempt_id');
    }

    public function submission(): HasOne
    {
        return $this->hasOne(AssessmentSubmission::class, 'attempt_id');
    }
}
