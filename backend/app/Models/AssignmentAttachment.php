<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teacher-uploaded attachment file for an assignment prompt (ARCH-002 FR-012).
 *
 * @Traced-To ARCH-002 FR-012, ARCH-002 QA-009 (ARCH-004 §4.1 Assignment Attachments)
 */
class AssignmentAttachment extends Model
{
    protected $fillable = [
        'assignment_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
    ];

    public $timestamps = false;

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
