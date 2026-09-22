<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Student file submission for an assignment (ARCH-002 FR-013, ARCH-002 FR-013).
 * UNIQUE(assignment_id, student_id) — one submission per student per assignment.
 *
 * @Traced-To ARCH-002 FR-013, ARCH-002 FR-013, ARCH-002 FR-013 (ARCH-004 §4.1 Assignment Submissions)
 */
class AssignmentSubmission extends Model
{
    protected $fillable = [
        'assignment_id',
        'student_id',
        'submitted_at',
        'status',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class, 'submission_id');
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(AssignmentFeedback::class, 'submission_id');
    }
}
