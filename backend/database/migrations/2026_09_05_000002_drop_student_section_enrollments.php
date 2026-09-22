<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U-10 — Drop the legacy section-level enrollment table.
     *
     * Hard delete: student_section_enrollments is superseded by
     * classroom_enrollments (ClassroomEnrollment is the only roster source).
     * Zero prod rows per plan, so migrate:fresh is the migration path
     * (no backfill). Idempotent via dropIfExists.
     */
    public function up(): void
    {
        Schema::dropIfExists('student_section_enrollments');
    }

    public function down(): void
    {
        // Irreversible by design: the legacy enrollment path was removed.
    }
};
