<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Individual item within an Assessment (ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015).
 * Each item is tagged with exactly one competency code (ARCH-002 FR-015).
 *
 * @Traced-To ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-015, ARCH-002 FR-018 (ARCH-004 §4.1 Assessment Items)
 */
class AssessmentItem extends Model
{
    protected $fillable = [
        'assessment_id',
        'item_type',
        'prompt',
        'max_points',
        'correct_answer',
        'competency_tag_id',
        'sort_order',
    ];

    protected $casts = [
        'max_points' => 'decimal:2',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function competencyTag(): BelongsTo
    {
        return $this->belongsTo(CompetencyReference::class, 'competency_tag_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssessmentItemAttachment::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AssessmentResponse::class, 'item_id');
    }
}
