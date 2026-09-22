<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-item manual grading ledger (ARCH-002 FR-018, ARCH-002 FR-018).
 * UNIQUE(assessment_submission_id, assessment_item_id) — one grade per item per submission.
 * is_draft gates persistence; only persisted (non-draft) grades feed mastery_records (ARCH-002 FR-019).
 *
 * @Traced-To ARCH-002 FR-018, ARCH-002 FR-018, ARCH-002 FR-019 (ARCH-004 §4.1, ARCH-001 §5.1)
 */
class GradeEntry extends Model
{
    protected $fillable = [
        'assessment_submission_id',
        'assessment_item_id',
        'score',
        'max_score',
        'feedback',
        'graded_by',
        'graded_at',
        'is_draft',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'is_draft' => 'boolean',
        'graded_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssessmentSubmission::class, 'assessment_submission_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'assessment_item_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
