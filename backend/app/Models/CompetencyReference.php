<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MATATAG competency reference repository (ARCH-002 FR-031, ARCH-002 FR-015).
 * Populated via batch import (UC-17) and referenced by assessment items,
 * mastery records, and learning materials.
 *
 * Semester-scoped: `semester` is '1', '2', or '3' as a string and pairs with
 * the owning Subject's Grade Level (School Year > Semester > Grade Level >
 * Subject > Competencies).
 *
 * @Traced-To ARCH-002 FR-031, ARCH-002 FR-015, ARCH-002 §2 (ARCH-001 §5.1 / ARCH-004 §4.1)
 */
class CompetencyReference extends Model
{
    protected $table = 'competency_reference';

    protected $fillable = [
        'code',
        'descriptor',
        'subject_id',
        'grade_level',
        'semester',
    ];

    protected $casts = [
        'grade_level' => 'string',
        'semester' => 'string',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
