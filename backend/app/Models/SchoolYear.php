<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolYear extends Model
{
    protected $fillable = ['name'];

    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    /**
     * Deprecated alias of semesters() for pre-restructure call sites.
     *
     * @deprecated Use semesters() instead.
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Semester::class);
    }
}
