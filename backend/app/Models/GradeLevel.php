<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grade Level (7-12) within a Semester. Owns class-grouping Sections and,
 * since the Semester restructure, directly owns Subjects
 * (Subject.grade_level_id).
 */
class GradeLevel extends Model
{
    protected $fillable = ['semester_id', 'grade_level'];

    /**
     * Backwards-compat alias: expose `term_id` alongside `semester_id` in
     * JSON so legacy clients reading `term_id` keep working while new code
     * prefers `semester_id`.
     */
    protected $appends = ['term_id'];

    /**
     * `grade_level` is a native PostgreSQL enum ('7','8','9','10','11','12';
     * ARCH-004 §10, Requirements Revision 2). The column is populated from an integer
     * on create, so cast to (string) before the value reaches the driver —
     * PostgreSQL will not implicitly cast an integer to an enum type (insert
     * would fail without this).
     */
    protected $casts = [
        'grade_level' => 'string',
    ];

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * `term_id` alias attribute (read-only): mirrors `semester_id`.
     */
    public function getTermIdAttribute(): ?int
    {
        return $this->attributes['semester_id'] ?? null;
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

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }
}
