<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Semester within a School Year (School Year > Semester > Grade Level >
 * Subject > Competencies). `semester` is '1', '2', or '3' as a string;
 * `name` is the human display label (e.g. "Semester 1").
 */
class Semester extends Model
{
    protected $table = 'semesters';

    protected $fillable = ['school_year_id', 'semester', 'name', 'start_date', 'end_date'];

    protected $casts = [
        'semester' => 'string',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function gradeLevels(): HasMany
    {
        return $this->hasMany(GradeLevel::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssessmentSubmission::class);
    }
}
