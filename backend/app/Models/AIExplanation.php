<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AI-generated post-assessment explanations (ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-027).
 * One primary explanation row per student per assessment item per attempt
 * (for items belonging to unmastered competencies) — ARCH-002 FR-027: the
 * assessment_attempt_id key lets explanations for different attempts of the
 * same student+assessment+item coexist; legacy rows keep NULL and stay
 * readable via fallback. Self-referencing via parent_explanation_id for
 * "Explain Further" follow-up turns (max 2, objective items only — ARCH-002 FR-029,
 * ARCH-002 FR-029).
 *
 * Append-only by design: rows are inserted on receipt (ARCH-002 FR-027) and never
 * updated/deleted. Teacher moderation (flag, note, disable) mutates boolean
 * fields through dedicated service methods, not through bulk operations.
 *
 * Semester-independent per ARCH-002 QA-010 — no semester_id FK; survives Semester-closure purge.
 * All FKs to assessment-related tables use RESTRICT; parent_explanation_id
 * self-FK uses CASCADE (follow-up deleted when parent deleted).
 *
 * @Traced-To ARCH-002 FR-027, ARCH-002 FR-027, ARCH-002 FR-028, ARCH-002 FR-027, ARCH-002 FR-028, ARCH-002 FR-029, ARCH-002 FR-029, ARCH-002 FR-030,
 *   ARCH-002 FR-027, ARCH-002 FR-029, ARCH-002 FR-028, ARCH-002 FR-028, ARCH-002 QA-008, ARCH-002 FR-027, ARCH-002 FR-030, ARCH-002 FR-030,
 *   ARCH-002 QA-008, ARCH-002 FR-028, ARCH-002 QA-002, ARCH-002 QA-010, ARCH-002 QA-010, ARCH-002 FR-027 (ARCH-004 §4.1, ARCH-005 block 4.7)
 */
class AIExplanation extends Model
{
    protected $table = 'ai_explanations';

    public const UPDATED_AT = null;

    protected $fillable = [
        'student_id',
        'assessment_id',
        'assessment_submission_id',
        'assessment_attempt_id',
        'item_id',
        'explanation_text',
        'is_ungrounded',
        'is_follow_up',
        'parent_explanation_id',
        'turn_number',
        'flagged',
        'flagged_by_teacher_id',
        'teacher_note',
        'teacher_note_updated_at',
        'explain_further_disabled',
        'disabled_by_teacher_id',
        'moderation_status',
    ];

    protected $casts = [
        'is_ungrounded' => 'boolean',
        'is_follow_up' => 'boolean',
        'turn_number' => 'integer',
        'flagged' => 'boolean',
        'teacher_note_updated_at' => 'datetime',
        'explain_further_disabled' => 'boolean',
        'assessment_attempt_id' => 'integer',
        'moderation_status' => 'string',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssessmentSubmission::class, 'assessment_submission_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'item_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AIExplanation::class, 'parent_explanation_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(AIExplanation::class, 'parent_explanation_id');
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by_teacher_id');
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by_teacher_id');
    }
}
