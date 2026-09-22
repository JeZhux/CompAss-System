<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 5 — Competency Mapping Engine: `not_competent_flags` table
     * (ARCH-004 §4.1 classroom-content tables, ARCH-001 §5.1 competency-mapping class design).
     *
     * Flags generated for Recorded Assessment results where mastery falls
     * below the 80% threshold (ARCH-002 FR-020 below-threshold recorded results flagged Not Competent). Written only for Recorded Assessments.
     *
     * The FK on `mastery_record_id` uses CASCADE (flag lifecycle tied to
     * parent mastery record). All other FKs use RESTRICT for purge protection.
     *
     * @Traced-To ARCH-002 FR-020 (below-threshold recorded results flagged Not Competent), FR-021 (unrecorded excluded from record/aggregates/flagging), QA-010 (term-scoped submission purge) (ARCH-004 §4.1 classroom-content tables)
     */
    public function up(): void
    {
        Schema::create('not_competent_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('competency_id')
                ->constrained('competency_reference')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assessment_submission_id')
                ->constrained('assessment_submissions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('mastery_record_id')
                ->constrained('mastery_records')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();

            // Gap report lookup (ARCH-002 FR-022 gap report of below-threshold competencies) and AI explanation trigger (ARCH-002 FR-027 explicit student-initiated generation after release).
            $table->index(['student_id', 'competency_id']);
            $table->index('assessment_submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('not_competent_flags');
    }
};
