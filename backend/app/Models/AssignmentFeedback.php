<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teacher written feedback on a student submission (ARCH-002 FR-014, ARCH-002 FR-014).
 * One feedback record per submission (UNIQUE submission_id).
 * Feedback-only — no grade or points column (ARCH-002 FR-014/ARCH-002 FR-012).
 *
 * @Traced-To ARCH-002 FR-014, ARCH-002 FR-014, ARCH-002 FR-012 (ARCH-004 §4.1 Assignment Feedback)
 */
class AssignmentFeedback extends Model
{
    protected $fillable = [
        'submission_id',
        'teacher_id',
        'feedback_text',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
