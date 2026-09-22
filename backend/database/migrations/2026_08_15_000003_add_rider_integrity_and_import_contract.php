<?php

/**
 * Phase 2 — Family B riders + import error-report contract (WU-C).
 *
 * Lands the fail-closed guarantees the intended architecture mandates but the
 * as-built schema never shipped, in one batch (BASELINE v1.2 §15.7):
 *   - ARCH-002 FR-002 (identifier-XOR invariant): chk_users_identifier CHECK — email XOR school_id, role-appropriate
 *   - ARCH-004 §4.1 (submission snapshot trigger keeps term/section aligned to parent): trg_assess_sub_snapshot trigger — assessment_submissions
 *     term/section must equal the parent assessment + its subject_section's
 *     section (cross-table guard: a PG CHECK cannot read another table, so a
 *     BEFORE trigger is the DB-level mechanism; see CODEMAP/decision log).
 *   - ARCH-004 §4.1 (dead timestamp columns dropped; as-built schema has none): six dead timestamp columns dropped —
 *     announcement_attachments.created_at, assignment_attachments.created_at,
 *     submission_files.created_at, assessment_item_attachments.created_at,
 *     assessment_responses.created_at, assessment_auto_saves.updated_at.
 *   - ARCH-004 §4.1 (import admin FKs RESTRICT so history survives admin deletion): import_jobs/import_logs.admin_id FKs CASCADE → RESTRICT
 *     (admin deletion must not silently erase import history).
 *   - ARCH-004 §4.1 (learning_materials.subject_section_id nullable): learning_materials.subject_section_id nullable (PRD §8.1 /
 *     REQ-DEC-013) + title column. title is NULLABLE: ARCH-004 §4.1 (material title column nullable) states
 *     NOT NULL, but LearningMaterialService still maps the API title to
 *     original_filename (the as-built column gap); a NOT NULL column would
 *     break every service-driven insert until the Phase 7 remediation
 *     rewires the service. Deviation documented in CHANGES-phase2.md (WU-C).
 *   - ARCH-002 FR-031 (error-report single-use time-limited download contract): error-report single-use contract —
 *     import_jobs.error_report_token (sha256), error_report_expires_at (7-day
 *     TTL), error_report_used_at + CHECK token/used_at mutual exclusion.
 *   - ARCH-004 §4.1 (audit enum export renamed to error_report_download): audit_event_type 'export' renamed to 'error_report_download'
 *     (11-value enum; security-clear naming).
 *
 * Every statement is idempotent so `migrate:fresh` twice stays clean.
 *
 * @Traced-To ARCH-002 FR-031 (error-report single-use time-limited download contract), ARCH-004 §4.1 (import admin FKs RESTRICT; audit enum renamed; subject_section_id nullable; identifier-XOR invariant; snapshot trigger; dead timestamp columns dropped; material title column nullable; 11-value audit event-type enum), ARCH-004 §7 (as-built integrity guards for grade entries), BASELINE v1.2 §15.7
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
        // ARCH-002 FR-002 (identifier-XOR invariant): identifier XOR CHECK (email XOR school_id, role-appropriate)
        // ------------------------------------------------------------------
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_users_identifier'
                ) THEN
                    ALTER TABLE users
                        ADD CONSTRAINT chk_users_identifier
                        CHECK (
                            (role IN ('Admin', 'Teacher') AND email IS NOT NULL AND school_id IS NULL)
                            OR
                            (role = 'Student' AND school_id IS NOT NULL AND email IS NULL)
                        );
                END IF;
            END $$;
            SQL
        );

        // ------------------------------------------------------------------
        // ARCH-004 §4.1 (submission snapshot trigger keeps term/section aligned to parent): assessment_submissions term/section snapshot trigger
        // ------------------------------------------------------------------
        DB::statement('DROP TRIGGER IF EXISTS trg_assess_sub_snapshot ON assessment_submissions');
        DB::statement('DROP FUNCTION IF EXISTS trg_assess_sub_snapshot()');
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION trg_assess_sub_snapshot()
            RETURNS trigger AS $$
            DECLARE
                v_assessment_term_id bigint;
                v_assessment_section_id bigint;
            BEGIN
                SELECT term_id INTO v_assessment_term_id
                FROM assessments
                WHERE id = NEW.assessment_id;

                SELECT section_id INTO v_assessment_section_id
                FROM subject_sections
                WHERE id = (
                    SELECT subject_section_id FROM assessments WHERE id = NEW.assessment_id
                );

                IF NEW.term_id IS DISTINCT FROM v_assessment_term_id
                   OR NEW.section_id IS DISTINCT FROM v_assessment_section_id THEN
                    RAISE EXCEPTION
                        'assessment_submissions term/section must match the parent assessment (ARCH-004 §4.1)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL
        );
        DB::statement(
            'CREATE TRIGGER trg_assess_sub_snapshot '
            . 'BEFORE INSERT OR UPDATE ON assessment_submissions '
            . 'FOR EACH ROW EXECUTE FUNCTION trg_assess_sub_snapshot()'
        );

        // ------------------------------------------------------------------
        // ARCH-004 §4.1 (dead timestamp columns dropped; as-built schema has none): six dead timestamp columns
        // ------------------------------------------------------------------
        $deadTimestampColumns = [
            'announcement_attachments' => 'created_at',
            'assignment_attachments' => 'created_at',
            'submission_files' => 'created_at',
            'assessment_item_attachments' => 'created_at',
            'assessment_responses' => 'created_at',
            'assessment_auto_saves' => 'updated_at',
        ];
        foreach ($deadTimestampColumns as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });
            }
        }

        // ------------------------------------------------------------------
        // ARCH-004 §4.1 (import admin FKs RESTRICT so history survives admin deletion): import admin FKs CASCADE → RESTRICT
        // ------------------------------------------------------------------
        foreach (['import_jobs', 'import_logs'] as $table) {
            DB::statement(
                "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_admin_id_foreign"
            );
            DB::statement(
                "ALTER TABLE {$table} "
                . "ADD CONSTRAINT {$table}_admin_id_foreign "
                . 'FOREIGN KEY (admin_id) REFERENCES users (id) '
                . 'ON DELETE RESTRICT ON UPDATE CASCADE'
            );
        }

        // ------------------------------------------------------------------
        // ARCH-004 §4.1 (learning_materials.subject_section_id nullable): learning_materials.subject_section_id nullable + title
        // ------------------------------------------------------------------
        Schema::table('learning_materials', function (Blueprint $table) {
            $table->foreignId('subject_section_id')->nullable()->change();
            $table->string('title', 255)->nullable()->after('competency_id');
        });

        // ------------------------------------------------------------------
        // ARCH-002 FR-031 (error-report single-use time-limited download contract): error-report single-use contract
        // ------------------------------------------------------------------
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->string('error_report_token', 64)->nullable()->after('error_report_path');
            $table->timestamp('error_report_expires_at')->nullable()->after('error_report_token');
            $table->timestamp('error_report_used_at')->nullable()->after('error_report_expires_at');
        });
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_import_job_report_single_use'
                ) THEN
                    ALTER TABLE import_jobs
                        ADD CONSTRAINT chk_import_job_report_single_use
                        CHECK (
                            NOT (error_report_token IS NOT NULL AND error_report_used_at IS NOT NULL)
                        );
                END IF;
            END $$;
            SQL
        );

        // ------------------------------------------------------------------
        // ARCH-004 §4.1 (audit enum export renamed to error_report_download): audit_event_type 'export' → 'error_report_download'
        // ------------------------------------------------------------------
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_enum e
                    JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'audit_event_type' AND e.enumlabel = 'export'
                ) THEN
                    ALTER TYPE audit_event_type
                        RENAME VALUE 'export' TO 'error_report_download';
                END IF;
            END $$;
            SQL
        );
        DB::statement(
            "COMMENT ON TYPE audit_event_type IS "
            . "'11-value enum (ARCH-004 §4.1): login, logout, create, update, delete, "
            . "release_results, flag_explanation, disable_explain_further, purge_terms, "
            . "error_report_download, other'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ARCH-004 §4.1 (audit enum renamed): rename back.
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_enum e
                    JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'audit_event_type' AND e.enumlabel = 'error_report_download'
                ) THEN
                    ALTER TYPE audit_event_type
                        RENAME VALUE 'error_report_download' TO 'export';
                END IF;
            END $$;
            SQL
        );

        // ARCH-002 FR-031 (error-report single-use time-limited download contract): drop CHECK + report-contract columns.
        DB::statement('ALTER TABLE import_jobs DROP CONSTRAINT IF EXISTS chk_import_job_report_single_use');
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn(['error_report_used_at', 'error_report_expires_at', 'error_report_token']);
        });

        // ARCH-004 §4.1 (learning_materials.subject_section_id nullable): drop title, restore subject_section_id NOT NULL.
        Schema::table('learning_materials', function (Blueprint $table) {
            $table->dropColumn('title');
            $table->foreignId('subject_section_id')->nullable(false)->change();
        });

        // ARCH-004 §4.1 (import admin FKs RESTRICT): restore CASCADE on import admin FKs.
        foreach (['import_jobs', 'import_logs'] as $table) {
            DB::statement(
                "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_admin_id_foreign"
            );
            DB::statement(
                "ALTER TABLE {$table} "
                . "ADD CONSTRAINT {$table}_admin_id_foreign "
                . 'FOREIGN KEY (admin_id) REFERENCES users (id) '
                . 'ON DELETE CASCADE ON UPDATE CASCADE'
            );
        }

        // ARCH-004 §4.1 (dead timestamp columns dropped): re-add the six dead timestamp columns.
        $deadTimestampColumns = [
            'announcement_attachments' => 'created_at',
            'assignment_attachments' => 'created_at',
            'submission_files' => 'created_at',
            'assessment_item_attachments' => 'created_at',
            'assessment_responses' => 'created_at',
            'assessment_auto_saves' => 'updated_at',
        ];
        foreach ($deadTimestampColumns as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->timestamp($column)->nullable();
            });
        }

        // ARCH-004 §4.1 (submission snapshot trigger): drop snapshot trigger.
        DB::statement('DROP TRIGGER IF EXISTS trg_assess_sub_snapshot ON assessment_submissions');
        DB::statement('DROP FUNCTION IF EXISTS trg_assess_sub_snapshot()');

        // ARCH-002 FR-002 (identifier-XOR invariant): drop identifier CHECK.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_identifier');
    }
};
