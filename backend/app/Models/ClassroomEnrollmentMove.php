<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of single-learner hand placements and moves
 * (ARCH-004 §4.1, ARCH-003 ADR-011, ARCH-005 block 4.17).
 *
 * One row per place or move carrying actor, learner, destination classroom,
 * source classroom (null on place), and time. Rows are inserted exclusively
 * by ClassroomService::placeLearner() / moveEnrollment() and are never
 * updated or deleted by application logic — corrections are new move rows.
 * The table has created_at but no updated_at column.
 */
class ClassroomEnrollmentMove extends Model
{
    public const ACTION_PLACED = 'placed';

    public const ACTION_MOVED = 'moved';

    protected $table = 'classroom_enrollment_moves';

    public $timestamps = false;

    protected $fillable = [
        'classroom_id',
        'source_classroom_id',
        'student_id',
        'actor_id',
        'action',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function sourceClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'source_classroom_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
