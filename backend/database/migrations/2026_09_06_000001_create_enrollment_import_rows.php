<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U1R (ADR-017) — Staged retention for bulk learner import group/year.
     *
     * group/year are OPTIONAL validation-only staged values: they are checked
     * against org vocabulary (sections/grade_levels) but never drive placement
     * and never create org rows. Confirmed rows are retained here — and ONLY
     * here — alongside the row error and audit metadata (import_job_id,
     * row_number, timestamps). Canonical tables (users, classroom_enrollments,
     * classrooms) gain NO columns.
     */
    public function up(): void
    {
        Schema::create('enrollment_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')
                ->constrained('import_jobs')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->integer('row_number');
            $table->string('learner_code');
            $table->string('full_name');
            $table->string('group_assignment')->nullable();
            $table->string('year_level')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index('import_job_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_import_rows');
    }
};
