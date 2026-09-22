<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomLeaveHistory extends Model
{
    protected $table = 'classroom_leave_history';

    public $timestamps = false;

    protected $fillable = ['classroom_id', 'student_id', 'left_at'];

    protected $casts = [
        'left_at' => 'datetime',
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
