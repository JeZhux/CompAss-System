<?php

/**
 * Phase A (CompAss rework) — CompAss ID for ALL roles, email removed.
 *
 * - Drops the legacy chk_users_identifier CHECK (email XOR school_id,
 *   role-appropriate) from 2026_08_15_000003.
 * - Drops the users.email column (app never deployed, no back-compat).
 * - Makes users.school_id NOT NULL UNIQUE (CompAss ID for every role).
 * - Adds chk_users_school_id_format: school_id ~ '^(ADM|TEA|STU)-[0-9]{4}-[0-9]{5}$'.
 * - Adds chk_users_school_id_role_prefix: prefix must match role
 *   (Admin→ADM-*, Teacher→TEA-*, Student→STU-*).
 *
 * Every statement is idempotent so `migrate:fresh` twice stays clean.
 * NOTE: on non-fresh databases holding legacy rows (NULL school_id,
 * non-conforming IDs, or emails), the NOT NULL / CHECK steps fail closed —
 * re-seed via migrate:fresh (no legacy support per Phase A brief).
 *
 * Reversibility: down() is best-effort, NOT fully reversible. It always
 * restores the email column (nullable unique), drops the new CHECKs, and
 * relaxes school_id to nullable — but the legacy chk_users_identifier CHECK
 * is only re-added when every existing row satisfies email-XOR. Phase A
 * databases (school_id-only rows, email dropped) can never satisfy it, so
 * the restore is skipped with a NOTICE instead of failing closed with 23514.
 * Pinned by MigrationDownReversibilityTest.
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
        // Retire the email-XOR invariant (replaced by the checks below).
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_identifier');

        // Drop the email column (with its unique index).
        if (Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['email']);
            });
        }
        if (Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email');
            });
        }

        // CompAss ID is mandatory for every role.
        DB::statement('ALTER TABLE users ALTER COLUMN school_id SET NOT NULL');

        // Keep the global UNIQUE on school_id (created nullable by the base
        // migration — re-add only if missing, e.g. custom schema history).
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'users_school_id_unique'
                ) AND NOT EXISTS (
                    SELECT 1 FROM pg_indexes WHERE indexname = 'users_school_id_unique'
                ) THEN
                    ALTER TABLE users ADD CONSTRAINT users_school_id_unique UNIQUE (school_id);
                END IF;
            END $$;
            SQL
        );

        // Format: <ROLE>-<4 digits>-<5 digits>.
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_users_school_id_format'
                ) THEN
                    ALTER TABLE users
                        ADD CONSTRAINT chk_users_school_id_format
                        CHECK (school_id ~ '^(ADM|TEA|STU)-[0-9]{4}-[0-9]{5}$');
                END IF;
            END $$;
            SQL
        );

        // Prefix must match role.
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_users_school_id_role_prefix'
                ) THEN
                    ALTER TABLE users
                        ADD CONSTRAINT chk_users_school_id_role_prefix
                        CHECK (
                            (role = 'Admin' AND school_id LIKE 'ADM-%')
                            OR
                            (role = 'Teacher' AND school_id LIKE 'TEA-%')
                            OR
                            (role = 'Student' AND school_id LIKE 'STU-%')
                        );
                END IF;
            END $$;
            SQL
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_school_id_role_prefix');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_school_id_format');

        // Idempotent restore: re-add the email column only when missing so a
        // repeated down() (or a down on a pre-Phase-A schema) never fails
        // with a duplicate-column error.
        if (! Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->unique()->nullable()->after('name');
            });
        }

        DB::statement('ALTER TABLE users ALTER COLUMN school_id DROP NOT NULL');

        // Restore the legacy email-XOR invariant (as defined in
        // 2026_08_15_000003) for environments rolling back — but only when
        // the existing rows satisfy it. Phase A rows (school_id-only, email
        // dropped) can never satisfy email-XOR, so an unconditional ADD
        // would fail closed with 23514 on any database holding Phase A
        // accounts (e.g. DatabaseMigrations rollback after a test that
        // created school_id-only users). Skip the restore in that case with
        // a notice; the retired invariant is documented as unrestorable once
        // emails are gone.
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_users_identifier'
                ) AND NOT EXISTS (
                    SELECT 1 FROM users
                    WHERE NOT (
                        (role IN ('Admin', 'Teacher') AND email IS NOT NULL AND school_id IS NULL)
                        OR
                        (role = 'Student' AND school_id IS NOT NULL AND email IS NULL)
                    )
                ) THEN
                    ALTER TABLE users
                        ADD CONSTRAINT chk_users_identifier
                        CHECK (
                            (role IN ('Admin', 'Teacher') AND email IS NOT NULL AND school_id IS NULL)
                            OR
                            (role = 'Student' AND school_id IS NOT NULL AND email IS NULL)
                        );
                ELSE
                    RAISE NOTICE 'Skipping chk_users_identifier restore: existing rows predate the email-XOR invariant.';
                END IF;
            END $$;
            SQL
        );
    }
};
