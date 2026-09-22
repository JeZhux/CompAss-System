<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Teacher-uploaded attachment file for an assessment item (ARCH-002 FR-015).
 * Retained with the parent Assessment; NOT subject to ARCH-002 QA-010 purge (ARCH-002 QA-010 exempts Assessment data).
 *
 * @Traced-To ARCH-002 FR-015, ARCH-002 QA-009 (ARCH-004 §4.1 Assessment Item Attachments)
 */
class AssessmentItemAttachment extends Model
{
    protected $fillable = [
        'assessment_item_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
    ];

    public $timestamps = false;

    public function assessmentItem(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'assessment_item_id');
    }
}
