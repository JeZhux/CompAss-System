<?php

/**
 * Requirements Revision 2 — widen grade scope from Grades 7–9 to Grades 7–12.
 *
 * Adds '10', '11', '12' to BOTH PostgreSQL-native grade-level enum types:
 *   - grade_levels_grade_level_enum         → grade_levels.grade_level
 *     (created by 0001_01_01_100001_create_lms_core_tables.php)
 *   - competency_reference_grade_level_enum → competency_reference.grade_level
 *     (created by 0001_01_02_000001_create_batch_import_tables.php)
 *
 * Every label addition is guarded on pg_enum, so the migration is safe to run
 * against a development database that was already widened manually and
 * `migrate:fresh` twice stays clean (same convention as the WU-C riders).
 *
 * Transaction caveat: PostgreSQL < 12 forbids ALTER TYPE ... ADD VALUE inside
 * a transaction block, and Laravel wraps each pgsql migration in one. This
 * project runs PostgreSQL >= 12, where the statement is allowed inside a
 * transaction provided the new value is not USED before commit — this
 * migration only adds labels and comments, never reads or writes them.
 *
 * down() note: PostgreSQL has no ALTER TYPE ... DROP VALUE, so reversing the
 * widening rebuilds each type at its original three-label domain; the final
 * cast fails loudly if any row still carries a widened value.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (array_keys($this->targets()) as $enumType) {
            foreach (['10', '11', '12'] as $label) {
                DB::statement(sprintf(
                    <<<'SQL'
                    DO $$
                    BEGIN
                        IF NOT EXISTS (
                            SELECT 1 FROM pg_enum e
                            JOIN pg_type t ON t.oid = e.enumtypid
                            WHERE t.typname = '%s' AND e.enumlabel = '%s'
                        ) THEN
                            ALTER TYPE %s ADD VALUE '%s';
                        END IF;
                    END $$;
                    SQL,
                    $enumType,
                    $label,
                    $enumType,
                    $label
                ));
            }

            DB::statement(
                "COMMENT ON TYPE {$enumType} IS "
                . "'grade scope per Requirements Revision 2: Grades 7-12'"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->targets() as $enumType => $table) {
            // Detach the column, rebuild the type at its original domain,
            // then cast back (fails loudly on any widened row still present).
            DB::statement("ALTER TABLE {$table} "
                . 'ALTER COLUMN grade_level TYPE varchar(255) '
                . 'USING grade_level::text');
            DB::statement("DROP TYPE {$enumType}");
            DB::statement("CREATE TYPE {$enumType} AS ENUM ('7', '8', '9')");
            DB::statement("ALTER TABLE {$table} "
                . "ALTER COLUMN grade_level TYPE {$enumType} "
                . "USING grade_level::{$enumType}");
        }
    }

    /**
     * Widened enum type → owning table (column is `grade_level` on both).
     *
     * @return array<string, string>
     */
    private function targets(): array
    {
        return [
            'grade_levels_grade_level_enum' => 'grade_levels',
            'competency_reference_grade_level_enum' => 'competency_reference',
        ];
    }
};
