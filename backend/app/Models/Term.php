<?php

namespace App\Models;

/**
 * Deprecated alias of Semester.
 *
 * The `terms` table was renamed to `semesters` (Semester hierarchy
 * restructure). This alias exists only so pre-restructure call sites keep
 * booting/running during the transition — new code MUST use Semester and
 * the `semester` / `semester_id` vocabulary. Scheduled for removal once
 * Agent 2 rewrites the controllers/services still referencing Term.
 *
 * @deprecated Use App\Models\Semester instead.
 */
class Term extends Semester
{
    protected $table = 'semesters';
}
