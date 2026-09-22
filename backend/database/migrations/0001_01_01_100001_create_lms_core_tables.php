<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Phase 1 — LMS Core tables per ARCH-004 §4.1 (organizational-spine tables).
     * Created in FK-topology order (ARCH-004 §3 entity-relationship overview / DevPlan §2):
     *   Level 0 (root):    school_years, subjects
     *   Level 1:           terms
     *   Level 2:           grade_levels
     *   Level 3:           sections
     *   Level 4:           subject_sections
     *   Level 5:           teacher_subject_section_assignments, student_section_enrollments
     *
     * ON DELETE actions per ARCH-004 §4.1 (organizational-spine tables) FK specifications.
     */
    public function up(): void
    {
        DB::statement('DROP TYPE IF EXISTS grade_levels_grade_level_enum CASCADE');
        DB::statement("CREATE TYPE grade_levels_grade_level_enum AS ENUM ('7', '8', '9')");

        // Level 0 — school_years (root, per DevPlan §2 Level 0)
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Level 0 — subjects (root; admin-managed per ARCH-004 §10 subjects centrally administered)
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Level 1 — terms → school_years (CASCADE)
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')
                ->constrained('school_years')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            $table->index('school_year_id');
        });

        DB::statement(
            'ALTER TABLE terms ADD CONSTRAINT chk_terms_end_after_start CHECK (end_date >= start_date)'
        );

        // Level 2 — grade_levels → terms (CASCADE); grades 7–12 (widened by 2026_08_23 Rev 2)
        Schema::create('grade_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')
                ->constrained('terms')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('grade_level');
            $table->timestamps();
            $table->index('term_id');
        });

        // Cast the grade_level column to the native PostgreSQL enum type.
        // ARCH-004 §4.1 (organizational-spine tables): grade_level has NO default — the value must be explicitly
        // provided on INSERT (ARCH-004 §10 grade band 7-12 accepted, out-of-range rejected: grades 7–9 scoped).
        DB::statement(
            'ALTER TABLE grade_levels '
            . 'ALTER COLUMN grade_level TYPE grade_levels_grade_level_enum '
            . 'USING grade_level::grade_levels_grade_level_enum'
        );

        // Level 3 — sections → grade_levels (CASCADE)
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')
                ->constrained('grade_levels')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('name');
            $table->timestamps();
            $table->index('grade_level_id');
        });

        // Level 4 — subject_sections → subjects + sections (both RESTRICT)
        Schema::create('subject_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('section_id')
                ->constrained('sections')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['subject_id', 'section_id']);
            $table->index('subject_id');
            $table->index('section_id');
        });

        // Level 5 — teacher_subject_section_assignments → subject_sections + users
        // UNIQUE(subject_section_id) enforces exactly-one-teacher-per-subject-section (ARCH-002 FR-005 one teacher per subject-section)
        Schema::create('teacher_subject_section_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_section_id')
                ->unique()
                ->constrained('subject_sections')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->timestamps();
            $table->index('teacher_id');
        });

        // Level 5 — student_section_enrollments → sections (CASCADE) + users (RESTRICT)
        Schema::create('student_section_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('section_id')
                ->constrained('sections')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamps();
            $table->unique(['student_id', 'section_id']);
            $table->index('student_id');
            $table->index('section_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_section_enrollments');
        Schema::dropIfExists('teacher_subject_section_assignments');
        Schema::dropIfExists('subject_sections');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('grade_levels');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('school_years');
        DB::statement('DROP TYPE IF EXISTS grade_levels_grade_level_enum CASCADE');
    }
};
