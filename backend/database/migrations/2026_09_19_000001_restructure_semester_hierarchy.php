<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Agent 1 — Semester hierarchy restructure.
     *
     * Target hierarchy (School Year > Semester > Grade Level > Subject > Competencies):
     *   school_years → semesters → grade_levels → subjects → competency_reference
     *   sections stay under grade_levels (class groupings such as 7-A).
     *
     * Terminology: ONLY "Semester" (values '1', '2', '3' as strings). The word
     * "Term" must not appear in new code, comments, or UI strings.
     *
     * DECISIONS (see return report for rationale):
     *   1. RENAME terms → semesters (full rename, not a parallel table). The
     *      codebase is pre-deployment so no zero-downtime compat table is
     *      needed. App\Models\Term is kept as a deprecated alias of the new
     *      App\Models\Semester so old call sites keep booting until Agent 2
     *      rewrites them.
     *   2. Semesters carry an explicit `semester` column ('1','2','3') plus the
     *      existing display `name`; UNIQUE(school_year_id, semester).
     *   3. Subjects are scoped under Grade Level via subjects.grade_level_id
     *      (FK → grade_levels, CASCADE). Global UNIQUE(name)/UNIQUE(code) are
     *      dropped in favour of UNIQUE(grade_level_id, code) — the same code
     *      may exist once per Grade Level (which already implies one
     *      Semester). grade_level_id stays NULLABLE as a transitional state
     *      for legacy unscoped rows.
     *   4. competency_reference gains `semester` VARCHAR NOT NULL DEFAULT '1'
     *      with a CHECK IN ('1','2','3'); existing rows backfill to '1'.
     *      subject_id FK + grade_level string are kept; cross-consistency with
     *      the newly scoped subjects is enforced at the application layer
     *      (Agent 2 follow-up).
     *   5. subject_sections + teacher_subject_section_assignments are DROPPED
     *      (not deprecated): every holder of subject_section_id
     *      (classrooms, announcements, assignments, assessments,
     *      mastery_records, learning_materials) gains a direct subject_id FK
     *      backfilled from the old join; classrooms additionally gains
     *      section_id to retain the class grouping. Teacher scope is now
     *      derived from classrooms (teacher_id + subject_id + school_year);
     *      ownership checks move to classroom scope (Agent 2 follow-up).
     *   6. term_id columns are renamed to semester_id on grade_levels,
     *      assignments, assessments, and assessment_submissions. The
     *      assessment_submissions snapshot trigger is rebuilt for Semester
     *      semantics (semester must match the parent assessment; section must
     *      match the parent assessment's classroom section).
     *
     * All steps are idempotent (guarded on hasTable/hasColumn/pg_constraint/
     * pg_indexes) so migrate:fresh stays clean on re-runs. down() restores
     * the previous schema shape (best-effort, no data remap — acceptable
     * pre-deployment).
     */
    public function up(): void
    {
        // Drop the old snapshot trigger first: it reads terms.term_id and
        // subject_sections, both of which are reshaped below.
        DB::statement('DROP TRIGGER IF EXISTS trg_assess_sub_snapshot ON assessment_submissions');
        DB::statement('DROP FUNCTION IF EXISTS trg_assess_sub_snapshot()');

        $this->renameTermsToSemesters();
        $this->renameTermIdColumnsToSemesterId();
        $this->scopeSubjectsUnderGradeLevel();
        $this->addCompetencySemester();
        $this->replaceSubjectSectionsWithSubjects();
        $this->renamePurgeTermsAuditEvent();
        $this->recreateSubmissionSnapshotTrigger();
    }

    /**
     * Reverse the restructure (schema shape only; data remap is NOT restored).
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_assess_sub_snapshot ON assessment_submissions');
        DB::statement('DROP FUNCTION IF EXISTS trg_assess_sub_snapshot()');

        // 1. Restore subject_sections + teacher assignments shells (schema only).
        if (! Schema::hasTable('subject_sections')) {
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
        }
        if (! Schema::hasTable('teacher_subject_section_assignments')) {
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
        }

        // 2. Restore subject_section_id holders (nullable; no backfill).
        // Drop the classroom uniqueness constraint FIRST: its backing index
        // matches the subject_id/section_id index scan below and DROP INDEX
        // on a constraint-owned index fails (keep-tests-booting fix).
        DB::statement('ALTER TABLE classrooms DROP CONSTRAINT IF EXISTS classrooms_teacher_subject_section_year_unique');
        foreach (['classrooms', 'announcements', 'assignments', 'assessments', 'mastery_records', 'learning_materials'] as $table) {
            if (Schema::hasColumn($table, 'subject_id')) {
                $this->dropForeignKeysOnColumn($table, 'subject_id');
                $this->dropIndexesOnColumn($table, 'subject_id');
            }
        }
        if (Schema::hasColumn('classrooms', 'section_id')) {
            $this->dropForeignKeysOnColumn('classrooms', 'section_id');
            $this->dropIndexesOnColumn('classrooms', 'section_id');
        }
        foreach (['classrooms', 'announcements', 'assignments', 'assessments', 'mastery_records', 'learning_materials'] as $table) {
            if (! Schema::hasColumn($table, 'subject_section_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->foreignId('subject_section_id')
                        ->nullable()
                        ->constrained('subject_sections')
                        ->restrictOnDelete()
                        ->cascadeOnUpdate();
                });
            }
        }
        Schema::table('classrooms', function (Blueprint $table): void {
            if (Schema::hasColumn('classrooms', 'subject_id')) {
                $table->dropColumn('subject_id');
            }
            if (Schema::hasColumn('classrooms', 'section_id')) {
                $table->dropColumn('section_id');
            }
        });
        foreach (['announcements', 'assignments', 'assessments', 'mastery_records', 'learning_materials'] as $holder) {
            Schema::table($holder, function (Blueprint $blueprint) use ($holder): void {
                if (Schema::hasColumn($holder, 'subject_id')) {
                    $blueprint->dropColumn('subject_id');
                }
            });
        }

        // 3. Drop competency semester.
        DB::statement('DROP INDEX IF EXISTS competency_reference_subject_id_semester_index');
        DB::statement('ALTER TABLE competency_reference DROP CONSTRAINT IF EXISTS chk_competency_reference_semester_valid');
        if (Schema::hasColumn('competency_reference', 'semester')) {
            Schema::table('competency_reference', function (Blueprint $table): void {
                $table->dropColumn('semester');
            });
        }

        // 4. Unscope subjects.
        if (Schema::hasColumn('subjects', 'grade_level_id')) {
            $this->dropForeignKeysOnColumn('subjects', 'grade_level_id');
            $this->dropIndexesOnColumn('subjects', 'grade_level_id');
            DB::statement('ALTER TABLE subjects DROP CONSTRAINT IF EXISTS subjects_grade_level_code_unique');
            Schema::table('subjects', function (Blueprint $table): void {
                $table->dropColumn('grade_level_id');
            });
        }
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS subjects_name_unique ON subjects (name)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS subjects_code_unique ON subjects (code)');

        // 5. Rename semester_id back to term_id.
        foreach (['grade_levels', 'assignments', 'assessments', 'assessment_submissions'] as $table) {
            if (Schema::hasColumn($table, 'semester_id')) {
                $this->dropForeignKeysOnColumn($table, 'semester_id');
                $this->dropIndexesOnColumn($table, 'semester_id');
                DB::statement("ALTER TABLE {$table} RENAME COLUMN semester_id TO term_id");
            }
        }

        // 6. Rename semesters back to terms.
        if (Schema::hasTable('semesters') && ! Schema::hasTable('terms')) {
            Schema::rename('semesters', 'terms');
        }
        if (Schema::hasTable('terms')) {
            DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS semesters_school_year_id_semester_unique');
            DB::statement('DROP INDEX IF EXISTS semesters_school_year_id_semester_unique');
            DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS chk_semesters_semester_valid');
            if (Schema::hasColumn('terms', 'semester')) {
                Schema::table('terms', function (Blueprint $table): void {
                    $table->dropColumn('semester');
                });
            }
            foreach (['grade_levels', 'assignments', 'assessments', 'assessment_submissions'] as $table) {
                if (Schema::hasColumn($table, 'term_id')) {
                    DB::statement(sprintf(
                        <<<'SQL'
                        DO $$
                        BEGIN
                            IF NOT EXISTS (
                                SELECT 1 FROM pg_constraint WHERE conname = '%1$s_term_id_foreign'
                            ) THEN
                                ALTER TABLE %1$s ADD CONSTRAINT %1$s_term_id_foreign
                                    FOREIGN KEY (term_id) REFERENCES terms (id)
                                    ON DELETE %2$s ON UPDATE CASCADE;
                            END IF;
                        END $$;
                        SQL,
                        $table,
                        $table === 'grade_levels' ? 'CASCADE' : 'RESTRICT'
                    ));
                }
            }
        }

        // 7. Audit enum label back.
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_enum e
                    JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'audit_event_type' AND e.enumlabel = 'purge_semesters'
                ) THEN
                    ALTER TYPE audit_event_type
                        RENAME VALUE 'purge_semesters' TO 'purge_terms';
                END IF;
            END $$;
            SQL
        );
    }

    // ------------------------------------------------------------------
    // Step 1 — terms → semesters (+ explicit semester '1'/'2'/'3').
    // ------------------------------------------------------------------
    private function renameTermsToSemesters(): void
    {
        if (Schema::hasTable('terms') && ! Schema::hasTable('semesters')) {
            Schema::rename('terms', 'semesters');
        }
        if (! Schema::hasTable('semesters')) {
            return;
        }

        if (! Schema::hasColumn('semesters', 'semester')) {
            Schema::table('semesters', function (Blueprint $table): void {
                $table->string('semester', 2)->nullable();
            });
        }

        // Backfill: first digit 1-3 found in the display name wins
        // ('1st Trimester' → '1', '1st Quarter' → '1', 'Semester 2' → '2');
        // anything else defaults to Semester '1'.
        DB::statement(
            <<<'SQL'
            UPDATE semesters SET semester = CASE
                WHEN name ~ '(^|[^0-9])3([^0-9]|$)' THEN '3'
                WHEN name ~ '(^|[^0-9])2([^0-9]|$)' THEN '2'
                ELSE '1'
            END
            WHERE semester IS NULL OR semester NOT IN ('1', '2', '3')
            SQL
        );

        $duplicates = DB::table('semesters')
            ->select('school_year_id', 'semester', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('school_year_id', 'semester')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($duplicates->isNotEmpty()) {
            $pairs = $duplicates->map(
                fn (object $row): string => sprintf(
                    'school_year_id=%d semester=%s (%d rows)',
                    $row->school_year_id,
                    $row->semester,
                    $row->duplicate_count
                )
            )->implode('; ');

            throw new RuntimeException(
                'Cannot add UNIQUE(school_year_id, semester) on semesters: '
                . 'duplicate rows exist [' . $pairs . ']. Merge the duplicate '
                . 'Semester rows deliberately (grade_levels hang off these rows '
                . 'via ON DELETE CASCADE, so re-parent children first) and re-run.'
            );
        }

        DB::statement("ALTER TABLE semesters ALTER COLUMN semester SET DEFAULT '1'");
        DB::statement('ALTER TABLE semesters ALTER COLUMN semester SET NOT NULL');
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_semesters_semester_valid'
                ) THEN
                    ALTER TABLE semesters
                        ADD CONSTRAINT chk_semesters_semester_valid
                        CHECK (semester IN ('1', '2', '3'));
                END IF;
            END $$;
            SQL
        );
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_indexes
                    WHERE schemaname = current_schema()
                      AND indexname = 'semesters_school_year_id_semester_unique'
                ) THEN
                    CREATE UNIQUE INDEX semesters_school_year_id_semester_unique
                        ON semesters (school_year_id, semester);
                END IF;
            END $$;
            SQL
        );
        // Rename the carried-over date-order CHECK to Semester terminology.
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_terms_end_after_start'
                ) AND NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_semesters_end_after_start'
                ) THEN
                    ALTER TABLE semesters
                        RENAME CONSTRAINT chk_terms_end_after_start TO chk_semesters_end_after_start;
                END IF;
            END $$;
            SQL
        );
        DB::statement(
            "COMMENT ON TABLE semesters IS "
            . "'School Year Semesters: semester ''1''/''2''/''3'' within a School Year; "
            . "parent of Grade Levels.'"
        );
    }

    // ------------------------------------------------------------------
    // Step 2 — term_id → semester_id on Semester-scoped tables.
    // ------------------------------------------------------------------
    private function renameTermIdColumnsToSemesterId(): void
    {
        $onDelete = [
            'grade_levels' => 'CASCADE',
            'assignments' => 'RESTRICT',
            'assessments' => 'RESTRICT',
            'assessment_submissions' => 'RESTRICT',
        ];

        foreach ($onDelete as $table => $deleteAction) {
            if (! Schema::hasColumn($table, 'term_id')) {
                continue;
            }
            $this->dropForeignKeysOnColumn($table, 'term_id');
            $this->dropIndexesOnColumn($table, 'term_id');
            DB::statement("ALTER TABLE {$table} RENAME COLUMN term_id TO semester_id");
            DB::statement(sprintf(
                <<<'SQL'
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint WHERE conname = '%1$s_semester_id_foreign'
                    ) THEN
                        ALTER TABLE %1$s ADD CONSTRAINT %1$s_semester_id_foreign
                            FOREIGN KEY (semester_id) REFERENCES semesters (id)
                            ON DELETE %2$s ON UPDATE CASCADE;
                    END IF;
                END $$;
                SQL,
                $table,
                $deleteAction
            ));
        }

        // grade_levels keeps its one-row-per-grade invariant, now Semester-scoped.
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_indexes
                    WHERE schemaname = current_schema()
                      AND indexname = 'grade_levels_semester_id_grade_level_unique'
                ) THEN
                    CREATE UNIQUE INDEX grade_levels_semester_id_grade_level_unique
                        ON grade_levels (semester_id, grade_level);
                END IF;
            END $$;
            SQL
        );
        DB::statement('CREATE INDEX IF NOT EXISTS grade_levels_semester_id_index ON grade_levels (semester_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS assignments_semester_id_index ON assignments (semester_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS assessments_semester_id_index ON assessments (semester_id)');
    }

    // ------------------------------------------------------------------
    // Step 3 — scope subjects under a Grade Level.
    // ------------------------------------------------------------------
    private function scopeSubjectsUnderGradeLevel(): void
    {
        if (! Schema::hasColumn('subjects', 'grade_level_id')) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->foreignId('grade_level_id')
                    ->nullable()
                    ->constrained('grade_levels')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
                $table->index('grade_level_id', 'subjects_grade_level_id_index');
            });
        }

        // Backfill legacy global subjects onto the earliest Grade Level so
        // every pre-existing row lands somewhere deterministic. When the
        // database has no Grade Levels yet (fresh install), rows stay NULL
        // until the application assigns them (transitional state, documented).
        DB::statement(
            <<<'SQL'
            UPDATE subjects SET grade_level_id = (SELECT MIN(id) FROM grade_levels)
            WHERE grade_level_id IS NULL AND EXISTS (SELECT 1 FROM grade_levels)
            SQL
        );

        // A Subject code is unique per Grade Level (a Grade Level already
        // implies one Semester), not globally.
        DB::statement('ALTER TABLE subjects DROP CONSTRAINT IF EXISTS subjects_name_unique');
        DB::statement('ALTER TABLE subjects DROP CONSTRAINT IF EXISTS subjects_code_unique');
        DB::statement('DROP INDEX IF EXISTS subjects_name_unique');
        DB::statement('DROP INDEX IF EXISTS subjects_code_unique');
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'subjects_grade_level_code_unique'
                ) THEN
                    ALTER TABLE subjects
                        ADD CONSTRAINT subjects_grade_level_code_unique
                        UNIQUE (grade_level_id, code);
                END IF;
            END $$;
            SQL
        );
    }

    // ------------------------------------------------------------------
    // Step 4 — competency_reference.semester ('1'/'2'/'3').
    // ------------------------------------------------------------------
    private function addCompetencySemester(): void
    {
        if (! Schema::hasColumn('competency_reference', 'semester')) {
            Schema::table('competency_reference', function (Blueprint $table): void {
                $table->string('semester', 2)->default('1');
            });
        }

        DB::statement(
            "UPDATE competency_reference SET semester = '1' "
            . "WHERE semester IS NULL OR semester NOT IN ('1', '2', '3')"
        );
        DB::statement("ALTER TABLE competency_reference ALTER COLUMN semester SET DEFAULT '1'");
        DB::statement('ALTER TABLE competency_reference ALTER COLUMN semester SET NOT NULL');
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'chk_competency_reference_semester_valid'
                ) THEN
                    ALTER TABLE competency_reference
                        ADD CONSTRAINT chk_competency_reference_semester_valid
                        CHECK (semester IN ('1', '2', '3'));
                END IF;
            END $$;
            SQL
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS competency_reference_subject_id_semester_index '
            . 'ON competency_reference (subject_id, semester)'
        );
    }

    // ------------------------------------------------------------------
    // Step 5 — replace subject_sections with direct subject_id (+ section
    // on classrooms), then drop subject_sections and its teacher assignments.
    // ------------------------------------------------------------------
    private function replaceSubjectSectionsWithSubjects(): void
    {
        $holders = [
            'classrooms',
            'announcements',
            'assignments',
            'assessments',
            'mastery_records',
            'learning_materials',
        ];

        foreach ($holders as $table) {
            if (! Schema::hasColumn($table, 'subject_id')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->foreignId('subject_id')
                        ->nullable()
                        ->constrained('subjects')
                        ->restrictOnDelete()
                        ->cascadeOnUpdate();
                });
            }
        }
        if (! Schema::hasColumn('classrooms', 'section_id')) {
            Schema::table('classrooms', function (Blueprint $table): void {
                $table->foreignId('section_id')
                    ->nullable()
                    ->constrained('sections')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        // Migrate data through the old join before it is dropped. Every
        // non-nullable source column is FK-guarded, so every row resolves.
        if (Schema::hasTable('subject_sections')) {
            foreach ($holders as $table) {
                if (Schema::hasColumn($table, 'subject_section_id')) {
                    DB::statement(
                        "UPDATE {$table} t SET subject_id = ss.subject_id "
                        . 'FROM subject_sections ss '
                        . 'WHERE t.subject_section_id = ss.id AND t.subject_id IS NULL'
                    );
                }
            }
            if (Schema::hasColumn('classrooms', 'subject_section_id')) {
                DB::statement(
                    'UPDATE classrooms c SET section_id = ss.section_id '
                    . 'FROM subject_sections ss '
                    . 'WHERE c.subject_section_id = ss.id AND c.section_id IS NULL'
                );
            }
        }

        // Fail loudly on unmigrated rows instead of silently nulling scope.
        // learning_materials.subject_id intentionally stays NULLABLE (its
        // source column was already nullable).
        $required = [
            'classrooms' => ['subject_id', 'section_id'],
            'announcements' => ['subject_id'],
            'assignments' => ['subject_id'],
            'assessments' => ['subject_id'],
            'mastery_records' => ['subject_id'],
        ];
        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $remaining = DB::table($table)->whereNull($column)->count();
                if ($remaining > 0) {
                    throw new RuntimeException(
                        "Cannot make {$table}.{$column} NOT NULL: {$remaining} row(s) "
                        . 'could not be migrated from subject_sections. Resolve the '
                        . 'orphan rows manually and re-run.'
                    );
                }
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
            }
        }

        // Drop the old join columns (FKs + indexes first).
        DB::statement('ALTER TABLE classrooms DROP CONSTRAINT IF EXISTS classrooms_teacher_subject_year_unique');
        DB::statement('DROP INDEX IF EXISTS classrooms_teacher_subject_year_unique');
        foreach ($holders as $holder) {
            if (Schema::hasColumn($holder, 'subject_section_id')) {
                $this->dropForeignKeysOnColumn($holder, 'subject_section_id');
                $this->dropIndexesOnColumn($holder, 'subject_section_id');
                Schema::table($holder, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('subject_section_id');
                });
            }
        }

        // Fresh scope indexes + per-year classroom uniqueness on the new key.
        DB::statement('CREATE INDEX IF NOT EXISTS classrooms_subject_id_index ON classrooms (subject_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS classrooms_section_id_index ON classrooms (section_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS announcements_subject_id_index ON announcements (subject_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS assignments_subject_id_index ON assignments (subject_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS assessments_subject_id_index ON assessments (subject_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS mastery_records_subject_id_index ON mastery_records (subject_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS learning_materials_subject_id_index ON learning_materials (subject_id)');
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'classrooms_teacher_subject_section_year_unique'
                ) THEN
                    ALTER TABLE classrooms
                        ADD CONSTRAINT classrooms_teacher_subject_section_year_unique
                        UNIQUE (teacher_id, subject_id, section_id, school_year);
                END IF;
            END $$;
            SQL
        );

        // Teacher scope now derives from classrooms; the join tables go away.
        Schema::dropIfExists('teacher_subject_section_assignments');
        Schema::dropIfExists('subject_sections');
    }

    // ------------------------------------------------------------------
    // Step 6 — audit enum: purge_terms → purge_semesters.
    // ------------------------------------------------------------------
    private function renamePurgeTermsAuditEvent(): void
    {
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pg_enum e
                    JOIN pg_type t ON t.oid = e.enumtypid
                    WHERE t.typname = 'audit_event_type' AND e.enumlabel = 'purge_terms'
                ) THEN
                    ALTER TYPE audit_event_type
                        RENAME VALUE 'purge_terms' TO 'purge_semesters';
                END IF;
            END $$;
            SQL
        );
        DB::statement(
            "COMMENT ON TYPE audit_event_type IS "
            . "'11-value enum: login, logout, create, update, delete, "
            . "release_results, flag_explanation, disable_explain_further, purge_semesters, "
            . "error_report_download, other'"
        );
    }

    // ------------------------------------------------------------------
    // Step 7 — snapshot trigger rebuilt for Semester semantics.
    // ------------------------------------------------------------------
    private function recreateSubmissionSnapshotTrigger(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION trg_assess_sub_snapshot()
            RETURNS trigger AS $$
            DECLARE
                v_assessment_semester_id bigint;
                v_assessment_classroom_id bigint;
                v_assessment_section_id bigint;
            BEGIN
                SELECT semester_id, classroom_id
                  INTO v_assessment_semester_id, v_assessment_classroom_id
                  FROM assessments
                 WHERE id = NEW.assessment_id;

                IF v_assessment_classroom_id IS NOT NULL THEN
                    SELECT section_id INTO v_assessment_section_id
                      FROM classrooms
                     WHERE id = v_assessment_classroom_id;
                END IF;

                IF NEW.semester_id IS DISTINCT FROM v_assessment_semester_id
                   OR (
                        v_assessment_classroom_id IS NOT NULL
                        AND NEW.section_id IS DISTINCT FROM v_assessment_section_id
                      ) THEN
                    RAISE EXCEPTION
                        'assessment_submissions semester/section must match the parent assessment (Semester scope)';
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
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * Drop every FK constraint carried by one column (names discovered from
     * the catalog so historic renames cannot desync this migration).
     */
    private function dropForeignKeysOnColumn(string $table, string $column): void
    {
        $names = DB::select(
            'SELECT c.conname AS name FROM pg_constraint c '
            . 'JOIN pg_class t ON t.oid = c.conrelid '
            . 'JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (c.conkey) '
            . "WHERE t.relname = ? AND a.attname = ? AND c.contype = 'f'",
            [$table, $column]
        );
        foreach ($names as $row) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$row->name}");
        }
    }

    /**
     * Drop every index whose definition mentions one column, skipping
     * constraint-owned backing indexes (DROP INDEX on those fails; drop
     * the constraint instead).
     */
    private function dropIndexesOnColumn(string $table, string $column): void
    {
        $names = DB::select(
            'SELECT i.indexname AS name FROM pg_indexes i '
            . 'LEFT JOIN pg_constraint c ON c.conname = i.indexname AND c.conrelid = (SELECT oid FROM pg_class WHERE relname = ?) '
            . 'WHERE i.schemaname = current_schema() AND i.tablename = ? AND i.indexdef ILIKE ? AND c.conname IS NULL',
            [$table, $table, '%' . $column . '%']
        );
        foreach ($names as $row) {
            DB::statement("DROP INDEX IF EXISTS {$row->name}");
        }
    }
};
