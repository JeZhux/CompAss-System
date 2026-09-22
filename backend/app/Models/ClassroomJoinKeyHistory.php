<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassroomJoinKeyHistory extends Model
{
    protected $table = 'classroom_join_key_history';

    public $timestamps = false;

    protected $fillable = ['classroom_id', 'old_join_key', 'revoked_at'];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
