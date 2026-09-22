<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Subject scoped under a Grade Level (School Year > Semester > Grade Level >
 * Subject > Competencies). `code` is unique per Grade Level (a Grade Level
 * already implies one Semester), not globally.
 */
class Subject extends Model
{
    protected $fillable = ['name', 'code', 'description', 'grade_level_id'];

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(CompetencyReference::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
