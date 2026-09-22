<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-item response data within a submission (ARCH-002 FR-017, ARCH-002 FR-018).
 * UNIQUE(submission_id, item_id) — one response per item per submission.
 * earned_points is NULL until scored.
 *
 * @Traced-To ARCH-002 FR-017, ARCH-002 FR-018, ARCH-003 ADR-004 (ARCH-004 §4.1 Assessment Responses)
 */
class AssessmentResponse extends Model
{
    protected $fillable = [
        'submission_id',
        'item_id',
        'response_text',
        'earned_points',
        'is_auto_scored',
        'scored_by_teacher_id',
    ];

    public $timestamps = false;

    protected $casts = [
        'earned_points' => 'decimal:2',
        'is_auto_scored' => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssessmentSubmission::class, 'submission_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'item_id');
    }

    public function scorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scored_by_teacher_id');
    }
}
