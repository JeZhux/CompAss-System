<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Completed assessment submission (ARCH-002 FR-017, ARCH-002 FR-018).
 * UNIQUE(attempt_id) — exactly one submission per attempt.
 * Status: pending_grading|scored. is_results_released gates student visibility (ARCH-002 FR-019).
 * Semester-scoped via semester_id (must match the parent assessment's
 * Semester — enforced by the trg_assess_sub_snapshot trigger).
 *
 * @Traced-To ARCH-002 FR-017, ARCH-002 FR-019, ARCH-002 FR-018 (ARCH-004 §4.1 Assessment Submissions)
 */
class AssessmentSubmission extends Model
{
    protected $fillable = [
        'attempt_id',
        'assessment_id',
        'student_id',
        'section_id',
        'semester_id',
        'submitted_at',
        'status',
        'is_results_released',
        'results_released_at',
    ];

    protected $casts = [
        'status' => 'string',
        'submitted_at' => 'datetime',
        'is_results_released' => 'boolean',
        'results_released_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'attempt_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * Deprecated alias of semester() for pre-restructure call sites.
     *
     * @deprecated Use semester() instead.
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AssessmentResponse::class, 'submission_id');
    }
}
