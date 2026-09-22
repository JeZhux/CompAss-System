<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class grouping (e.g. 7-A) under a Grade Level. Subjects are NO LONGER
 * attached per-section — Subject hangs directly off the
 * Grade Level, and a Classroom pins (teacher, subject, section, school year).
 */
class Section extends Model
{
    protected $fillable = ['grade_level_id', 'name'];

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
