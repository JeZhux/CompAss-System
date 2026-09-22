<?php

/**
 * Phase B (CompAss rework) — full_name-only bulk imports.
 *
 * - Drops staged group/year columns from enrollment_import_rows
 *   (group_assignment, year_level) — sheets are full_name-only, the server
 *   auto-generates CompAss IDs, no staged placement metadata retained.
 * - Extends the import_type enum with 'teacher_application' for the parallel
 *   teacher bulk flow (student bulk keeps 'student_enrollment').
 *
 * The `learner_code` column is RETAINED: it stores the generated school_id
 * (STU-/TEA-) for audit continuity; the parent import_job's import_type
 * distinguishes student vs teacher bulk. Idempotent for migrate:fresh reruns.
 *
 * Reversibility: down() is explicitly PARTIAL and the enum change is
 * IRREVERSIBLE. down() re-adds the dropped group_assignment/year_level
 * columns (nullable), but Postgres enums cannot drop a single value without
 * recreating the type — the 'teacher_application' value is intentionally
 * left in place (re-run migrate:fresh to restore the base enum). Pinned by
 * MigrationDownReversibilityTest.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Postgres ALTER TYPE ... ADD VALUE cannot run inside a transaction
     * block — disable the DDL transaction wrapper for this migration.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('enrollment_import_rows', 'group_assignment')) {
            Schema::table('enrollment_import_rows', function (Blueprint $table) {
                $table->dropColumn('group_assignment');
            });
        }

        if (Schema::hasColumn('enrollment_import_rows', 'year_level')) {
            Schema::table('enrollment_import_rows', function (Blueprint $table) {
                $table->dropColumn('year_level');
            });
        }

        // Teacher bulk needs its own import_type. The base migration created
        // a postgres enum import_type_enum('student_enrollment','competency_tags').
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                <<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_enum e
                        JOIN pg_type t ON t.oid = e.enumtypid
                        WHERE t.typname = 'import_type_enum' AND e.enumlabel = 'teacher_application'
                    ) THEN
                        ALTER TYPE import_type_enum ADD VALUE 'teacher_application';
                    END IF;
                END $$;
                SQL
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('enrollment_import_rows', 'group_assignment')) {
            Schema::table('enrollment_import_rows', function (Blueprint $table) {
                $table->string('group_assignment')->nullable()->after('full_name');
            });
        }

        if (! Schema::hasColumn('enrollment_import_rows', 'year_level')) {
            Schema::table('enrollment_import_rows', function (Blueprint $table) {
                $table->string('year_level')->nullable()->after('group_assignment');
            });
        }

        // Postgres enums cannot drop a single value without recreating the
        // type — rolling back the teacher value requires a full type rebuild
        // and is intentionally not automated here (re-run migrate:fresh to
        // restore the base enum).
    }
};
