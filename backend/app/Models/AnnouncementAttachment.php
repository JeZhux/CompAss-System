<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File attachment for an announcement (ARCH-002 QA-009, ARCH-002 QA-009).
 *
 * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 QA-009 (ARCH-004 §4.1 Announcement Attachments)
 */
class AnnouncementAttachment extends Model
{
    protected $fillable = [
        'announcement_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
    ];

    public $timestamps = false;

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
