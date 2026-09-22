<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 3 — 3C: derived Not-Competent view replaces the stored
     * `not_competent_flags` table (ARCH-004 §4.4, ARCH-004 §4.4; supersedes
     * ARCH-004 §4.4's stored table).
     *
     * The view exposes the SAME columns the stored table did (id, student_id,
     * competency_id, assessment_submission_id, mastery_record_id, created_at) so
     * every consumer is drop-in. Row set = the LATEST Recorded mastery record per
     * (student, competency) — resolved FIRST via ROW_NUMBER with the
     * (created_at DESC, id DESC) tiebreak (ARCH-002 FR-020, FR-020) — where that latest
     * record's mastery_status = 'Not_Mastered'. A view cannot go stale
     * (REQ-DEC-009); the drop removes the append-only, never-deduplicated rows
     * that re-triggered AI and inflated gap reports after regrades (BRG-10).
     *
     * The ARCH-004 §7 index (idx_mr_stu_comp_created) lives on mastery_records and is
     * KEPT — the view reads through it; nothing else references it for removal.
     *
     * @Traced-To ARCH-004 §4.4, ARCH-002 FR-020, ARCH-004 §4.4, ARCH-004 §4.4, ARCH-002 FR-020, FR-020,
     *            BRG-10, REQ-DEC-009 (BASELINE v1.2 §15.4)
     */
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE VIEW v_not_competent_flags AS
            SELECT
                ranked.id,
                ranked.student_id,
                ranked.competency_id,
                ranked.assessment_submission_id,
                ranked.id AS mastery_record_id,
                ranked.created_at
            FROM (
                SELECT
                    mr.id,
                    mr.student_id,
                    mr.competency_id,
                    mr.assessment_submission_id,
                    mr.mastery_status,
                    mr.created_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY mr.student_id, mr.competency_id
                        ORDER BY mr.created_at DESC, mr.id DESC
                    ) AS rn
                FROM mastery_records mr
            ) ranked
            WHERE ranked.rn = 1 AND ranked.mastery_status = 'Not_Mastered'
            SQL
        );

        Schema::dropIfExists('not_competent_flags');
    }

    /**
     * Reverse the migrations — recreate the stored table, drop the view.
     */
    public function down(): void
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

            $table->index(['student_id', 'competency_id']);
            $table->index('assessment_submission_id');
        });

        DB::statement('DROP VIEW IF EXISTS v_not_competent_flags');
    }
};
