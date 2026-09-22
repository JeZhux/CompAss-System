<?php

/**
 * Phase 3 — WU-4: AI explanations per-attempt (ARCH-002 FR-027 explanations for unmastered items; persist-on-receipt keying).
 *
 * Explanations are generated at grading time against the current attempt, so
 * each attempt's explanations must coexist with prior attempts' rows. Today
 * UNIQUE (student_id, assessment_id, item_id, is_follow_up, turn_number)
 * forbids that. This migration:
 *   - adds ai_explanations.assessment_attempt_id (nullable, RESTRICT on
 *     delete per repo FK discipline) pointing at the attempt whose submission
 *     the explanation was generated for;
 *   - adds ai_explanations.moderation_status (string) with DB-level CHECK on
 *     the value domain; legacy rows default to 'auto_generated' (unmoderated
 *     — the current moderation model is the boolean `flagged` flag, ARCH-002 FR-030, no
 *     status string is emitted by AIService today);
 *   - backfills assessment_attempt_id via the join
 *     ai_explanations.assessment_submission_id → assessment_submissions.attempt_id
 *     (assessment_submissions.attempt_id is NOT NULL UNIQUE, so every
 *     explanation attached to a submission resolves exactly one attempt;
 *     rows that resolve to nothing stay NULL — PG nullable-UNIQUE allows that,
 *     mirroring ARCH-002 FR-017's autosave backfill);
 *   - rebuilds the UNIQUE constraint to (student_id, assessment_id, item_id,
 *     is_follow_up, turn_number, assessment_attempt_id): per-attempt
 *     explanations can coexist; legacy NULL rows keep the old semantics
 *     (PG treats NULLs as distinct in UNIQUE indexes).
 *
 * @Traced-To ARCH-002 FR-027 (BASELINE v1.2 §15.8)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_explanations', function (Blueprint $table) {
            $table->foreignId('assessment_attempt_id')
                ->nullable()
                ->constrained('assessment_attempts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('moderation_status', 20)->default('auto_generated');
        });

        DB::statement(
            'ALTER TABLE ai_explanations ADD CONSTRAINT ai_explanations_moderation_status_check '
            . "CHECK (moderation_status IN ('auto_generated', 'pending', 'approved', 'rejected'))"
        );

        // Backfill: resolve the attempt via submission → attempt (1:1).
        DB::statement(
            'UPDATE ai_explanations AS exp '
            . 'SET assessment_attempt_id = sub.attempt_id '
            . 'FROM assessment_submissions AS sub '
            . 'WHERE exp.assessment_submission_id = sub.id '
            . 'AND exp.assessment_attempt_id IS NULL'
        );

        // Rebuild the UNIQUE: per-attempt explanations coexist (ARCH-002 FR-027).
        DB::statement(
            'ALTER TABLE ai_explanations '
            . 'DROP CONSTRAINT IF EXISTS uq_ae_stu_asg_item_turn'
        );
        DB::statement(
            'ALTER TABLE ai_explanations '
            . 'ADD CONSTRAINT uq_ae_stu_asg_item_turn_attempt '
            . 'UNIQUE (student_id, assessment_id, item_id, is_follow_up, turn_number, assessment_attempt_id)'
        );

        Schema::table('ai_explanations', function (Blueprint $table) {
            $table->index('assessment_attempt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE ai_explanations '
            . 'DROP CONSTRAINT IF EXISTS uq_ae_stu_asg_item_turn_attempt'
        );
        DB::statement(
            'ALTER TABLE ai_explanations '
            . 'DROP CONSTRAINT IF EXISTS ai_explanations_moderation_status_check'
        );

        Schema::table('ai_explanations', function (Blueprint $table) {
            $table->dropIndex(['assessment_attempt_id']);
        });
        Schema::table('ai_explanations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assessment_attempt_id');
            $table->dropColumn('moderation_status');
        });

        // Restore the original constraint name/columns (create migration).
        Schema::table('ai_explanations', function (Blueprint $table) {
            $table->unique(
                ['student_id', 'assessment_id', 'item_id', 'is_follow_up', 'turn_number'],
                'uq_ae_stu_asg_item_turn'
            );
        });
    }
};
