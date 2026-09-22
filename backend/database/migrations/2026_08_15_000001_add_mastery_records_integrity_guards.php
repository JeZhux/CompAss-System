<?php

/**
 * Phase 2 — Family A: mastery_records DB-integrity guards (ARCH-004 §7 mastery_records DB-integrity guards + mastery UNIQUE guard).
 *
 * Adds the fail-closed guarantees the intended architecture mandates but the
 * as-built schema never shipped (ARCH-004 §7 as-built integrity guards for mastery/grade entries; BASELINE v1.2 §15.4/§15.7):
 *   - chk_mr_percent_range CHECK (mastery_percent BETWEEN 0 AND 100)
 *   - chk_mr_status_threshold CHECK (status↔threshold consistency)
 *   - uq_mr_stu_comp_assess_submission UNIQUE derivation guard (ARCH-004 §7 mastery UNIQUE derivation guard)
 *   - idx_mr_stu_comp_created DESC index recreate (ARCH-002 QA-001 dashboards within 3 seconds, FR-020 most-recent recorded result is current status)
 *   - trg_mr_recorded_only BEFORE INSERT trigger — Unrecorded assessments never
 *     persist mastery rows (ARCH-002 FR-021 unrecorded excluded from record/aggregates/flagging; ARCH-004 §7 as-built integrity guards for mastery records)
 *   - trg_assessments_type_immutable BEFORE UPDATE trigger on assessments
 *
 * Every statement is idempotent so `migrate:fresh` twice stays clean
 * (ARCH-004 §7 mastery_records DB-integrity guards Required Verification).
 *
 * @Traced-To ARCH-004 §7 (mastery_records DB-integrity guards; mastery UNIQUE guard blocks re-derivation duplicates), ARCH-002 FR-021 (unrecorded excluded from record/aggregates/flagging), FR-020 (recorded results persisted to mastery record; most-recent recorded result is current status), QA-001 (dashboards within 3 seconds), QA-010 (term-scoped submission purge) (BASELINE v1.2 §12/§15.4/§15.7)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ARCH-004 §7 (mastery_records DB-integrity guards): DROP-TYPE hygiene guard. Laravel's PG `$table->enum()` emits
        // varchar(255) + CHECK, so this native-type guard is a documented no-op
        // (idempotency hygiene, verified against PostgresGrammar).
        DB::statement('DROP TYPE IF EXISTS mastery_records_mastery_status_enum');

        // ARCH-004 §7 (mastery_records DB-integrity guards): recreate the dashboard derivation index with the mandated
        // (student_id, competency_id, created_at DESC) ordering (ARCH-002 QA-001 dashboards within 3 seconds, FR-020 most-recent recorded result is current status).
        DB::statement('DROP INDEX IF EXISTS mastery_records_student_id_competency_id_created_at_index');
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_mr_stu_comp_created '
            . 'ON mastery_records (student_id, competency_id, created_at DESC)'
        );

        // ARCH-004 §7 (mastery_records DB-integrity guards): CHECK (mastery_percent BETWEEN 0 AND 100).
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_mr_percent_range'
                ) THEN
                    ALTER TABLE mastery_records
                        ADD CONSTRAINT chk_mr_percent_range
                        CHECK (mastery_percent >= 0 AND mastery_percent <= 100);
                END IF;
            END $$;
            SQL
        );

        // ARCH-004 §7 (mastery_records DB-integrity guards): status↔threshold CHECK — ≥80% must be Mastered, <80% must be
        // Not_Mastered (ARCH-002 FR-020 80 percent mastery threshold; BASELINE v1.2 §15.4 formula).
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_mr_status_threshold'
                ) THEN
                    ALTER TABLE mastery_records
                        ADD CONSTRAINT chk_mr_status_threshold
                        CHECK (
                            (mastery_percent >= 80 AND mastery_status = 'Mastered')
                            OR (mastery_percent < 80 AND mastery_status = 'Not_Mastered')
                        );
                END IF;
            END $$;
            SQL
        );

        // ARCH-004 §7 (mastery_records DB-integrity guards): UNIQUE derivation guard (student, competency, assessment,
        // submission) — duplicate derivation runs are impossible (ARCH-004 §7 mastery UNIQUE derivation guard).
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'uq_mr_stu_comp_assess_submission'
                ) THEN
                    ALTER TABLE mastery_records
                        ADD CONSTRAINT uq_mr_stu_comp_assess_submission
                        UNIQUE (student_id, competency_id, assessment_id, assessment_submission_id);
                END IF;
            END $$;
            SQL
        );

        // ARCH-004 §7 (mastery UNIQUE guard blocks re-derivation duplicates): Recorded-only BEFORE INSERT trigger — zero-row guarantee for
        // Unrecorded assessments (ARCH-002 FR-021 unrecorded excluded from record/aggregates/flagging; ARCH-004 §7 as-built integrity guards for mastery records).
        DB::statement('DROP TRIGGER IF EXISTS trg_mr_recorded_only ON mastery_records');
        DB::statement('DROP FUNCTION IF EXISTS trg_mr_recorded_only()');
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION trg_mr_recorded_only()
            RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM assessments
                    WHERE id = NEW.assessment_id AND type = 'Recorded'
                ) THEN
                    RAISE EXCEPTION 'Unrecorded assessments never persist mastery records (ARCH-002 FR-020, FR-021)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL
        );
        DB::statement(
            'CREATE TRIGGER trg_mr_recorded_only '
            . 'BEFORE INSERT ON mastery_records '
            . 'FOR EACH ROW EXECUTE FUNCTION trg_mr_recorded_only()'
        );

        // ARCH-004 §7 (mastery UNIQUE guard blocks re-derivation duplicates): assessments.type immutability trigger (BASELINE v1.2 §15.4).
        DB::statement('DROP TRIGGER IF EXISTS trg_assessments_type_immutable ON assessments');
        DB::statement('DROP FUNCTION IF EXISTS trg_assessments_type_immutable()');
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION trg_assessments_type_immutable()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.type IS DISTINCT FROM OLD.type THEN
                    RAISE EXCEPTION 'Assessment type is immutable (ARCH-004 §7)';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL
        );
        DB::statement(
            'CREATE TRIGGER trg_assessments_type_immutable '
            . 'BEFORE UPDATE ON assessments '
            . 'FOR EACH ROW EXECUTE FUNCTION trg_assessments_type_immutable()'
        );
    }

    /**
     * Reverse the migrations — drop guards, restore the original ASC index.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_mr_recorded_only ON mastery_records');
        DB::statement('DROP TRIGGER IF EXISTS trg_assessments_type_immutable ON assessments');
        DB::statement('DROP FUNCTION IF EXISTS trg_mr_recorded_only()');
        DB::statement('DROP FUNCTION IF EXISTS trg_assessments_type_immutable()');
        DB::statement('ALTER TABLE mastery_records DROP CONSTRAINT IF EXISTS chk_mr_percent_range');
        DB::statement('ALTER TABLE mastery_records DROP CONSTRAINT IF EXISTS chk_mr_status_threshold');
        DB::statement('ALTER TABLE mastery_records DROP CONSTRAINT IF EXISTS uq_mr_stu_comp_assess_submission');
        DB::statement('DROP INDEX IF EXISTS idx_mr_stu_comp_created');
        DB::statement(
            'CREATE INDEX IF NOT EXISTS mastery_records_student_id_competency_id_created_at_index '
            . 'ON mastery_records (student_id, competency_id, created_at)'
        );
    }
};
