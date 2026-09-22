<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 5 — Competency Mapping Engine: `mastery_records` table
     * (ARCH-004 §4.1 classroom-content tables, ARCH-001 §5.1 competency-mapping class design).
     *
     * Per-student per-competency per-assessment mastery results (ARCH-002 FR-020 recorded results persisted to mastery record).
     * Written only for Recorded Assessments via CompetencyMappingService.
     * Append-only — forms a running history; "current" is derived
     * (most recent row per student + competency). Never updated in place (ARCH-002 FR-020 recorded results persisted to mastery record).
     *
     * Critical: all FKs use RESTRICT to prevent any cascade from Term-closure
     * purge (ARCH-002 QA-010 term-scoped submission purge). Mastery records must survive Term-scoped deletion.
     *
     * @Traced-To ARCH-002 FR-020 (per-competency mastery formula; 80 percent mastery threshold; recorded results persisted to mastery record; deterministic mastery math; 80 percent mastery threshold; zero-point competency groups skipped; most-recent recorded result is current status), FR-022 (class-level competency mastery report), QA-001 (dashboards within 3 seconds), FR-021 (unrecorded excluded from record/aggregates/flagging)
     */
    public function up(): void
    {
        Schema::create('mastery_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('subject_section_id')
                ->constrained('subject_sections')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_id')
                ->constrained('assessments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_submission_id')
                ->constrained('assessment_submissions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('competency_id')
                ->constrained('competency_reference')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->decimal('mastery_percent', 5, 2);
            $table->enum('mastery_status', ['Mastered', 'Not_Mastered']);
            $table->timestamp('created_at')->useCurrent();

            // Primary dashboard index — enables current-mastery derivation (ARCH-002 QA-001 dashboards within 3 seconds)
            // and student history view (ARCH-002 FR-024 student per-competency mastery history).
            $table->index(['student_id', 'competency_id', 'created_at']);
            $table->index('assessment_submission_id');
            $table->index('assessment_id');
            $table->index('subject_section_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mastery_records');
    }
};
