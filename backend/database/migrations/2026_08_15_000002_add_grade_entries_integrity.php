<?php

/**
 * Phase 2 — Family B: grade_entries DB-integrity guards (ARCH-004 §4.1 zero score legal; service-layer lower bound).
 *
 * Adds the fail-closed guarantees the intended architecture mandates but the
 * as-built schema never shipped (ARCH-004 §7 as-built integrity guards for grade entries; BASELINE v1.2
 * §15.7):
 *   - score / max_score widened to DECIMAL(8,2) (overflow-safe beyond 999.99)
 *   - chk_ge_score_non_negative CHECK (score >= 0)
 *   - chk_ge_score_not_over_max CHECK (score <= max_score)
 *   - trg_ge_snapshot_max_score BEFORE INSERT OR UPDATE trigger — max_score is
 *     a write-time snapshot of assessment_items.max_points; the authoritative
 *     snapshot wins over any client-sent max_score (ARCH-004 §7 grade-entries integrity guards). The trigger runs BEFORE the CHECK constraints evaluate,
 *     so the snapshot feeds the score <= max_score CHECK (trigger-before-CHECK
 *     ordering per implementation-plan §Phase 2 family B).
 *
 * The snapshot is taken at write time only: subsequent item edits do NOT
 * retro-update existing rows (snapshot preserved, ARCH-004 §7 grade-entries integrity guards).
 *
 * Every statement is idempotent so `migrate:fresh` twice stays clean
 * (ARCH-004 §4.1 zero score legal Required Verification).
 *
 * @Traced-To ARCH-004 §4.1 (zero score legal; service-layer lower bound), ARCH-004 §7 (grade-entries integrity guards; as-built integrity guards for grade entries), ARCH-002 FR-019 (pending-grading gate blocks release), FR-018 (manual scoring via Pending Grading queue; resubmissions as new attempts),
 *            D-004, B-007 (BASELINE v1.2 §15.7)
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
        // ARCH-004 §4.1 (zero score legal): DECIMAL(8,2) — point precision aligned with
        // assessment_items.max_points and assessment_responses.earned_points
        // (ARCH-004 §7 grade-entries integrity guards rationale: same quantity, one precision).
        Schema::table('grade_entries', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->change();
            $table->decimal('max_score', 8, 2)->change();
        });

        // ARCH-004 §4.1 (zero score legal): CHECK (score >= 0) — negative scores are impossible in the
        // ledger (D-004).
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_ge_score_non_negative'
                ) THEN
                    ALTER TABLE grade_entries
                        ADD CONSTRAINT chk_ge_score_non_negative
                        CHECK (score >= 0);
                END IF;
            END $$;
            SQL
        );

        // ARCH-004 §4.1 (zero score legal): CHECK (score <= max_score).
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_ge_score_not_over_max'
                ) THEN
                    ALTER TABLE grade_entries
                        ADD CONSTRAINT chk_ge_score_not_over_max
                        CHECK (score <= max_score);
                END IF;
            END $$;
            SQL
        );

        // ARCH-004 §4.1 (zero score legal): max_score write-time snapshot trigger. The authoritative
        // assessment_items.max_points wins over any client-sent max_score. Runs
        // BEFORE the CHECK constraints, so chk_ge_score_not_over_max validates
        // against the snapshot (ARCH-004 §7 grade-entries integrity guards; as-built integrity guards for grade entries).
        DB::statement('DROP TRIGGER IF EXISTS trg_ge_snapshot_max_score ON grade_entries');
        DB::statement('DROP FUNCTION IF EXISTS trg_ge_snapshot_max_score()');
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION trg_ge_snapshot_max_score()
            RETURNS trigger AS $$
            DECLARE
                item_max_points numeric(8, 2);
            BEGIN
                SELECT max_points INTO item_max_points
                FROM assessment_items
                WHERE id = NEW.assessment_item_id;

                IF item_max_points IS NOT NULL THEN
                    NEW.max_score := item_max_points;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL
        );
        DB::statement(
            'CREATE TRIGGER trg_ge_snapshot_max_score '
            . 'BEFORE INSERT OR UPDATE ON grade_entries '
            . 'FOR EACH ROW EXECUTE FUNCTION trg_ge_snapshot_max_score()'
        );
    }

    /**
     * Reverse the migrations — drop trigger/function/constraints, revert to
     * DECIMAL(5,2).
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_ge_snapshot_max_score ON grade_entries');
        DB::statement('DROP FUNCTION IF EXISTS trg_ge_snapshot_max_score()');
        DB::statement('ALTER TABLE grade_entries DROP CONSTRAINT IF EXISTS chk_ge_score_non_negative');
        DB::statement('ALTER TABLE grade_entries DROP CONSTRAINT IF EXISTS chk_ge_score_not_over_max');

        Schema::table('grade_entries', function (Blueprint $table) {
            $table->decimal('score', 5, 2)->change();
            $table->decimal('max_score', 5, 2)->change();
        });
    }
};
