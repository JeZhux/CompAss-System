<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomEnrollment extends Model
{
    protected $table = 'classroom_enrollments';

    public $timestamps = false;

    protected $fillable = ['classroom_id', 'student_id', 'joined_at'];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
