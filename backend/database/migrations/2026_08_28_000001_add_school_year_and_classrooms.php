<?php

use App\Support\AcademicYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * U01 — Backend migrations: teacher_assignments school_year + classrooms +
     * enrollments + academic-year helper.
     *
     * - Add school_year TEXT to teacher_subject_section_assignments
     *   (nullable → backfill current academic year → NOT NULL DEFAULT)
     * - Drop legacy UNIQUE(subject_section_id), replace with
     *   UNIQUE(teacher_id, subject_section_id, school_year) + INDEX school_year
     * - Create classrooms, classroom_join_key_history, classroom_enrollments
     */
    public function up(): void
    {
        // Compute current academic year via helper (Aug1 inclusive → Jul31).
        // Fallback inline if helper not autoloadable at migration time.
        $academicYear = null;
        if (class_exists(AcademicYear::class)) {
            try {
                if (method_exists(AcademicYear::class, 'for')) {
                    $academicYear = AcademicYear::for();
                } else {
                    $academicYear = AcademicYear::academicYearFor();
                }
            } catch (\Throwable) {
                $academicYear = null;
            }
        }
        if ($academicYear === null) {
            $now = \Illuminate\Support\Carbon::now();
            $y = (int) $now->format('Y');
            $m = (int) $now->format('n');
            $academicYear = $m >= 8 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y;
        }

        // 1) Add school_year column if missing (nullable initially for backfill)
        if (! Schema::hasColumn('teacher_subject_section_assignments', 'school_year')) {
            Schema::table('teacher_subject_section_assignments', function (Blueprint $table) {
                $table->text('school_year')->nullable();
            });
        }

        // Backfill existing rows with current academic year where null.
        DB::table('teacher_subject_section_assignments')
            ->whereNull('school_year')
            ->update(['school_year' => $academicYear]);

        // 2) Drop legacy UNIQUE(subject_section_id) — constraint + index.
        // Both forms are tried idempotently; PG names the constraint the same as the index.
        DB::statement('ALTER TABLE teacher_subject_section_assignments DROP CONSTRAINT IF EXISTS teacher_subject_section_assignments_subject_section_id_unique');
        DB::statement('DROP INDEX IF EXISTS teacher_subject_section_assignments_subject_section_id_unique');

        // 3) Enforce NOT NULL + DEFAULT on school_year after backfill.
        // Use DB statements so re-running on already-NOT-NULL stays clean.
        // Quote the default value safely (academic year is "YYYY-YYYY" digits+dash only, but we still use parameter binding via addSlashes).
        $quotedDefault = str_replace("'", "''", $academicYear);
        DB::statement("ALTER TABLE teacher_subject_section_assignments ALTER COLUMN school_year SET DEFAULT '{$quotedDefault}'");
        // Only set NOT NULL if any null remain after backfill we already did, but statement is idempotent.
        DB::statement('ALTER TABLE teacher_subject_section_assignments ALTER COLUMN school_year SET NOT NULL');

        // 4) Add per-year uniqueness UNIQUE(teacher_id, subject_section_id, school_year)
        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'tssa_teacher_subject_school_year_unique'
    ) THEN
        ALTER TABLE teacher_subject_section_assignments
            ADD CONSTRAINT tssa_teacher_subject_school_year_unique
            UNIQUE (teacher_id, subject_section_id, school_year);
    END IF;
END $$;
SQL);

        // Index on school_year for filtering.
        DB::statement('CREATE INDEX IF NOT EXISTS tssa_school_year_index ON teacher_subject_section_assignments (school_year)');

        // 5) Create classrooms table
        if (! Schema::hasTable('classrooms')) {
            Schema::create('classrooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teacher_id')
                    ->constrained('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->foreignId('subject_section_id')
                    ->constrained('subject_sections')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->text('school_year');
                $table->text('name');
                $table->text('suffix')->nullable();
                $table->text('join_key');
                $table->boolean('is_join_enabled')->default(true);
                $table->timestampTz('archived_at')->nullable();
                $table->timestampsTz();

                $table->unique(['teacher_id', 'subject_section_id', 'school_year'], 'classrooms_teacher_subject_year_unique');
                $table->unique('join_key', 'classrooms_join_key_unique');
                $table->index('teacher_id', 'classrooms_teacher_id_index');
                $table->index('subject_section_id', 'classrooms_subject_section_id_index');
                $table->index('school_year', 'classrooms_school_year_index');
            });

            // CHECK length(join_key) = 6
            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_classrooms_join_key_length'
    ) THEN
        ALTER TABLE classrooms
            ADD CONSTRAINT chk_classrooms_join_key_length CHECK (char_length(join_key) = 6);
    END IF;
END $$;
SQL);
        }

        // 6) Create classroom_join_key_history table
        if (! Schema::hasTable('classroom_join_key_history')) {
            Schema::create('classroom_join_key_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('classroom_id')
                    ->constrained('classrooms')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->text('old_join_key')->unique('classroom_join_key_history_old_join_key_unique');
                $table->timestampTz('revoked_at')->nullable();
                // No Laravel timestamps per spec; only revoked_at. Add created_at for audit? Spec does not list timestamps, so omit.
            });

            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'chk_classroom_join_key_history_old_key_length'
    ) THEN
        ALTER TABLE classroom_join_key_history
            ADD CONSTRAINT chk_classroom_join_key_history_old_key_length CHECK (char_length(old_join_key) = 6);
    END IF;
END $$;
SQL);
        }

        // 7) Create classroom_enrollments table
        if (! Schema::hasTable('classroom_enrollments')) {
            Schema::create('classroom_enrollments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('classroom_id')
                    ->constrained('classrooms')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->foreignId('student_id')
                    ->constrained('users')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->timestampTz('joined_at')->useCurrent();
                $table->unique(['classroom_id', 'student_id'], 'classroom_enrollments_classroom_student_unique');
                $table->index('student_id', 'classroom_enrollments_student_id_index');
            });
        }
    }

    public function down(): void
    {
        // Drop in FK dependency order.
        Schema::dropIfExists('classroom_enrollments');
        Schema::dropIfExists('classroom_join_key_history');
        Schema::dropIfExists('classrooms');

        // Drop per-year uniqueness + index, then school_year column, restore legacy unique.
        DB::statement('ALTER TABLE teacher_subject_section_assignments DROP CONSTRAINT IF EXISTS tssa_teacher_subject_school_year_unique');
        DB::statement('DROP INDEX IF EXISTS tssa_school_year_index');

        // Drop default + not null before dropping column (PG requires).
        // ALTER COLUMN DROP DEFAULT is idempotent.
        if (Schema::hasColumn('teacher_subject_section_assignments', 'school_year')) {
            DB::statement('ALTER TABLE teacher_subject_section_assignments ALTER COLUMN school_year DROP DEFAULT');
            // Remove NOT NULL before dropping column to avoid dependency issues; PG allows DROP COLUMN even with NOT NULL but we do it cleanly.
            DB::statement('ALTER TABLE teacher_subject_section_assignments ALTER COLUMN school_year DROP NOT NULL');
            Schema::table('teacher_subject_section_assignments', function (Blueprint $table) {
                $table->dropColumn('school_year');
            });
        }

        // Restore legacy UNIQUE(subject_section_id) if not exists and no duplicates block it.
        // This matches the original migration: subject_section_id UNIQUE RESTRICT.
        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'teacher_subject_section_assignments_subject_section_id_unique'
    ) AND NOT EXISTS (
        SELECT 1 FROM pg_indexes WHERE indexname = 'teacher_subject_section_assignments_subject_section_id_unique'
    ) THEN
        -- Only create if no duplicate subject_section_id rows currently exist; otherwise keep down partially applied and let operator deduplicate.
        IF NOT EXISTS (
            SELECT subject_section_id FROM teacher_subject_section_assignments GROUP BY subject_section_id HAVING COUNT(*) > 1
        ) THEN
            ALTER TABLE teacher_subject_section_assignments ADD CONSTRAINT teacher_subject_section_assignments_subject_section_id_unique UNIQUE (subject_section_id);
        END IF;
    END IF;
END $$;
SQL);
    }
};
