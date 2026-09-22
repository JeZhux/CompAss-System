<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teacher-uploaded reference materials aligned to competencies for RAG retrieval
 * (ARCH-002 FR-026). Semester-independent — no semester_id FK; persists across
 * Semester boundaries. File types restricted to PDF and DOCX (ARCH-002 QA-009).
 *
 * @Traced-To ARCH-002 FR-026, ARCH-002 QA-009, ARCH-002 QA-010 (ARCH-004 §4.1)
 */
class LearningMaterial extends Model
{
    protected $fillable = [
        'teacher_id',
        'subject_id',
        'competency_id',
        'title',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'extracted_text',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(CompetencyReference::class, 'competency_id');
    }
}
