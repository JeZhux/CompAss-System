<?php

/**
 * Phase 3 — WU-1: attempt lifecycle schema half (ARCH-004 §4.1 attempt lifecycle extras / ARCH-002 FR-017 per-attempt auto-save rows / FR-018 resubmission linkage fields).
 *
 * Adds the attempt-lifecycle state the resubmission + autosave contracts need
 * (BASELINE v1.2 §15.8; decision log ARCH-004 §4.1 attempt lifecycle extras / ARCH-002 FR-017 / FR-018):
 *   - assessment_attempts.started_at (ARCH-004 §4.1 attempt lifecycle extras) — set when the student starts
 *     the attempt; nullable until the start flow lands.
 *   - assessment_attempts resubmission state (ARCH-002 FR-018 resubmission linkage fields) — is_resubmission
 *     flag, resubmission_of_attempt_id self-FK (RESTRICT per repo FK
 *     discipline), resubmission_reason, resubmission_requested_at.
 *   - assessment_auto_saves.attempt_id (ARCH-002 FR-017 per-attempt auto-save rows) — per-attempt autosave rows
 *     replace the shared last-write-wins row: UNIQUE (student_id,
 *     assessment_id) becomes UNIQUE (student_id, assessment_id, attempt_id).
 *     Legacy rows are backfilled to the latest attempt per student+assessment
 *     where determinable, else left NULL (PG nullable-UNIQUE allows that);
 *     no rows are deleted (legacy semantics preserved).
 *
 * @Traced-To ARCH-004 §4.1 (attempt lifecycle extras), ARCH-002 FR-017 (per-attempt auto-save rows), FR-018 (resubmission linkage fields) (BASELINE v1.2 §15.8)
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
        // ------------------------------------------------------------------
        // ARCH-004 §4.1 (attempt lifecycle extras) + ARCH-002 FR-018 (resubmission linkage fields): attempt lifecycle columns
        // ------------------------------------------------------------------
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
            $table->boolean('is_resubmission')->default(false);
            $table->foreignId('resubmission_of_attempt_id')
                ->nullable()
                ->constrained('assessment_attempts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->text('resubmission_reason')->nullable();
            $table->timestamp('resubmission_requested_at')->nullable();
        });

        // ------------------------------------------------------------------
        // ARCH-002 FR-017 (per-attempt auto-save rows): per-attempt autosave keying
        // ------------------------------------------------------------------
        Schema::table('assessment_auto_saves', function (Blueprint $table) {
            $table->foreignId('attempt_id')
                ->nullable()
                ->constrained('assessment_attempts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });

        // ARCH-002 FR-017 (per-attempt auto-save rows): drop the shared-row UNIQUE, add the per-attempt UNIQUE.
        // The original constraint name comes from the as-built create
        // migration's $table->unique(['student_id', 'assessment_id']).
        DB::statement(
            'ALTER TABLE assessment_auto_saves '
            . 'DROP CONSTRAINT IF EXISTS assessment_auto_saves_student_id_assessment_id_unique'
        );
        DB::statement(
            'ALTER TABLE assessment_auto_saves '
            . 'ADD CONSTRAINT uq_autosave_student_assessment_attempt '
            . 'UNIQUE (student_id, assessment_id, attempt_id)'
        );

        // ARCH-002 FR-017 (per-attempt auto-save rows): backfill legacy rows — point each shared row at the latest
        // attempt for the same student+assessment; rows without a match stay
        // NULL (allowed by PG nullable-UNIQUE). No rows are deleted.
        DB::statement(
            <<<'SQL'
            UPDATE assessment_auto_saves AS autosave
            SET attempt_id = latest_attempt.id
            FROM (
                SELECT DISTINCT ON (student_id, assessment_id)
                    id, student_id, assessment_id
                FROM assessment_attempts
                ORDER BY student_id, assessment_id, id DESC
            ) AS latest_attempt
            WHERE autosave.student_id = latest_attempt.student_id
              AND autosave.assessment_id = latest_attempt.assessment_id
              AND autosave.attempt_id IS NULL
            SQL
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ARCH-002 FR-017 (per-attempt auto-save rows): restore the shared-row UNIQUE, drop the per-attempt key.
        DB::statement(
            'ALTER TABLE assessment_auto_saves '
            . 'DROP CONSTRAINT IF EXISTS uq_autosave_student_assessment_attempt'
        );
        Schema::table('assessment_auto_saves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attempt_id');
        });
        Schema::table('assessment_auto_saves', function (Blueprint $table) {
            $table->unique(['student_id', 'assessment_id']);
        });

        // ARCH-004 §4.1 (attempt lifecycle extras) + ARCH-002 FR-018 (resubmission linkage fields): drop the attempt lifecycle columns.
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resubmission_of_attempt_id');
            $table->dropColumn([
                'started_at',
                'is_resubmission',
                'resubmission_reason',
                'resubmission_requested_at',
            ]);
        });
    }
};
