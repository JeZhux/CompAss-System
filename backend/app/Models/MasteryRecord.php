<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Per-student per-competency per-assessment mastery results (ARCH-002 FR-020).
 * Written only for Recorded Assessments via CompetencyMappingService.
 * Append-only — forms a running history; "current" is derived (most recent
 * row per student + competency, ARCH-002 FR-020). Every FK uses RESTRICT for purge
 * protection (ARCH-002 QA-010). Not-Competent flags are a derived view over this
 * table (ARCH-004 §4.4, ARCH-004 §4.4) — no stored flag rows exist.
 *
 * @Traced-To ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-022, ARCH-002 FR-022, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-020, ARCH-002 FR-021, ARCH-002 FR-020, ARCH-002 FR-020
 * @Traced-To ARCH-002 FR-020, ARCH-002 QA-001, ARCH-002 QA-010 (ARCH-004 §4.1)
 */
class MasteryRecord extends Model
{
    public const UPDATED_AT = null;

    /**
     * U-05: classroom_id is required — every mastery row belongs to a
     * classroom. subject_id is the co-carried subject scope for pooled
     * analytics + latestPerPair continuity. FK is RESTRICT so deleting a
     * classroom never cascades to mastery history.
     */
    protected $fillable = [
        'student_id',
        'subject_id',
        'classroom_id',
        'assessment_id',
        'assessment_submission_id',
        'competency_id',
        'mastery_percent',
        'mastery_status',
    ];

    protected $casts = [
        'classroom_id' => 'integer',
        'mastery_percent' => 'decimal:2',
        'mastery_status' => 'string',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if ($record->classroom_id === null) {
                throw new \InvalidArgumentException(
                    'MasteryRecord classroom_id is required: every mastery row must belong to a classroom.'
                );
            }
            if ($record->subject_id !== null) {
                $classroom = Classroom::find($record->classroom_id);
                if ($classroom !== null && (int) $classroom->subject_id !== (int) $record->subject_id) {
                    throw new \InvalidArgumentException(
                        'MasteryRecord classroom_id subject_id mismatch: classroom '.$classroom->id
                        .' has subject_id '.$classroom->subject_id
                        .' but MasteryRecord has subject_id '.$record->subject_id
                    );
                }
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssessmentSubmission::class, 'assessment_submission_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(CompetencyReference::class, 'competency_id');
    }

    /**
     * U-05: required classroom scope — every mastery row belongs to a
     * classroom (NOT NULL, RESTRICT).
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /**
     * Constrain the query to the LATEST Recorded mastery record per
     * (student, competency) — resolved FIRST by a window subquery ordered
     * with the (created_at DESC, id DESC) tiebreak (ARCH-002 FR-020, ARCH-002 FR-020) — so
     * dashboard aggregates count each pair at most once and
     * mastery_rate_percent / remediation_frequency can never exceed 100%
     * (ARCH-004 §4.4). All dashboard derivations share this single subquery.
     *
     * When $partitionByAssessment is true, the latest record is resolved per
     * (assessment, student, competency) — the per-assessment snapshot
     * semantics of the performance-trends endpoint.
     *
     * @Traced-To ARCH-004 §4.4, ARCH-002 FR-020, ARCH-002 FR-020 (BASELINE v1.2 §15.4)
     */
    public function scopeLatestPerPair(Builder $query, bool $partitionByAssessment = false): Builder
    {
        $partition = $partitionByAssessment
            ? 'PARTITION BY mr.assessment_id, mr.student_id, mr.competency_id'
            : 'PARTITION BY mr.student_id, mr.competency_id';

        return $query->whereIn('mastery_records.id', function ($sub) use ($partition): void {
            $sub->select('id')
                ->fromSub(
                    DB::table('mastery_records as mr')
                        ->select(['mr.id'])
                        ->selectRaw(
                            'ROW_NUMBER() OVER (' . $partition . ' ORDER BY mr.created_at DESC, mr.id DESC) AS rn'
                        ),
                    'ranked'
                )
                ->where('rn', 1);
        });
    }
}
