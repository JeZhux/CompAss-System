<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Assessment definition with Recorded/Unrecorded type designation (ARCH-002 FR-015, ARCH-002 FR-015).
 * Status: draft|released (ARCH-002 FR-016, ARCH-002 FR-016).
 * Semester-scoped via semester_id; subject scope is direct (subject_id).
 *
 * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-016, ARCH-002 FR-016 (ARCH-004 §4.1 Assessments)
 */
class Assessment extends Model
{
    protected $fillable = [
        'teacher_id',
        'classroom_id',
        'subject_id',
        'semester_id',
        'title',
        'description',
        'type',
        'status',
        'time_limit',
        'availability_starts_at',
        'availability_ends_at',
    ];

    protected $casts = [
        'classroom_id' => 'integer',
        'type' => 'string',
        'status' => 'string',
        'time_limit' => 'integer',
        'availability_starts_at' => 'datetime',
        'availability_ends_at' => 'datetime',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
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

    public function items(): HasMany
    {
        return $this->hasMany(AssessmentItem::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssessmentSubmission::class);
    }
}
