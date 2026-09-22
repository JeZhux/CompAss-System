<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual file within a student submission (ARCH-002 FR-013, ARCH-002 QA-009).
 * Up to 5 per submission (application-enforced).
 *
 * @Traced-To ARCH-002 FR-013, ARCH-002 QA-009 (ARCH-004 §4.1 Submission Files)
 */
class SubmissionFile extends Model
{
    protected $fillable = [
        'submission_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
    ];

    public $timestamps = false;

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'submission_id');
    }
}
