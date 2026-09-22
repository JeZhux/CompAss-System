<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    protected $fillable = [
        'teacher_id',
        'subject_id',
        'section_id',
        'school_year',
        'name',
        'suffix',
        'join_key',
        'is_join_enabled',
        'archived_at',
    ];

    protected $casts = [
        'is_join_enabled' => 'boolean',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Classroom $classroom): void {
            // Auto-generate name as "{Section.name} {Subject.name}" + " - {suffix}" if suffix provided (trimmed, max 50).
            if (empty($classroom->name) && $classroom->subject_id && $classroom->section_id) {
                $section = Section::find($classroom->section_id);
                $subject = Subject::find($classroom->subject_id);
                if ($section && $subject) {
                    $base = $section->name . ' ' . $subject->name;
                    $suffix = $classroom->suffix !== null ? trim((string) $classroom->suffix) : null;
                    $classroom->suffix = $suffix !== '' ? $suffix : null;
                    $classroom->name = $suffix ? $base . ' - ' . $suffix : $base;
                }
            }
        });
    }

    public function getJoinKeyDisplayAttribute(): string
    {
        $key = (string) $this->join_key;

        if (strlen($key) !== 6) {
            return $key;
        }

        return substr($key, 0, 2) . '-' . substr($key, 2);
    }

    public function joinKeyHistory(): HasMany
    {
        return $this->hasMany(ClassroomJoinKeyHistory::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassroomEnrollment::class);
    }
}
