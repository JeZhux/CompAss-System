<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Announcement within a subject (ARCH-002 FR-011–ARCH-002 FR-011).
 *
 * @Traced-To ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011, ARCH-002 FR-011 (ARCH-004 §4.1 Annotations)
 */
class Announcement extends Model
{
    protected $fillable = [
        'teacher_id',
        'classroom_id',
        'subject_id',
        'title',
        'body',
    ];

    protected $casts = [
        'classroom_id' => 'integer',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AnnouncementAttachment::class);
    }
}
