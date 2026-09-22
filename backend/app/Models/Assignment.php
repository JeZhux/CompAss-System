<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Teacher-created assignment within a subject (ARCH-002 FR-012–ARCH-002 FR-013).
 * semester_id is denormalized for Semester-closure purge scoping.
 *
 * @Traced-To ARCH-002 FR-012, ARCH-002 FR-012, ARCH-002 FR-013, ARCH-002 QA-010 (ARCH-004 §4.1 Assignments)
 */
class Assignment extends Model
{
    protected $fillable = [
        'teacher_id',
        'classroom_id',
        'subject_id',
        'semester_id',
        'title',
        'description',
        'due_date',
    ];

    protected $casts = [
        'classroom_id' => 'integer',
        'due_date' => 'datetime',
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

    public function attachments(): HasMany
    {
        return $this->hasMany(AssignmentAttachment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
