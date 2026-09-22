<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit G1 — grade-level enums widened from Grades 7–9 to Grades 7–12.
 *
 * DB-level acceptance only (raw inserts): widening app-layer request
 * validation is unit G2 and deliberately out of scope here.
 */
#[Group('grade-enum-widen')]
class GradeLevelEnumWidenTest extends TestCase
{
    use RefreshDatabase;

    private int $termId;

    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $yearId = DB::table('school_years')->insertGetId(['name' => '2026-27']);
        $this->termId = DB::table('semesters')->insertGetId([
            'school_year_id' => $yearId,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $this->subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Mathematics',
            'code' => 'MATH',
        ]);
    }

    public function test_grades_seven_through_twelve_accepted_in_grade_levels(): void
    {
        foreach (['7', '8', '9', '10', '11', '12'] as $level) {
            $this->assertTrue(DB::table('grade_levels')->insert([
                'semester_id' => $this->termId,
                'grade_level' => $level,
            ]));
        }
    }

    public function test_grades_seven_through_twelve_accepted_in_competency_reference(): void
    {
        foreach (['7', '8', '9', '10', '11', '12'] as $level) {
            $this->assertTrue(DB::table('competency_reference')->insert([
                'code' => 'CR-G'.$level,
                'descriptor' => 'Competency descriptor for grade '.$level,
                'subject_id' => $this->subjectId,
                'grade_level' => $level,
            ]));
        }
    }

    public function test_out_of_scope_grades_rejected_in_grade_levels(): void
    {
        foreach (['6', '13'] as $level) {
            $this->assertInsertRejected('grade_levels', [
                'semester_id' => $this->termId,
                'grade_level' => $level,
            ]);
        }
    }

    public function test_out_of_scope_grades_rejected_in_competency_reference(): void
    {
        foreach (['6', '13'] as $level) {
            $this->assertInsertRejected('competency_reference', [
                'code' => 'CR-BAD-'.$level,
                'descriptor' => 'Must not persist',
                'subject_id' => $this->subjectId,
                'grade_level' => $level,
            ]);
        }
    }

    /**
     * Criterion 3 — re-running the widening logic on an already-widened
     * database must be a guarded no-op, not a failure.
     */
    public function test_widening_migration_is_idempotent_on_already_widened_database(): void
    {
        // RefreshDatabase has already applied the migration via migrate:fresh;
        // executing the very same migration object a second time must not fail.
        $migration = require database_path(
            'migrations/2026_08_23_000001_widen_grade_level_enums_to_twelve.php'
        );
        $migration->up();

        foreach (['grade_levels_grade_level_enum', 'competency_reference_grade_level_enum'] as $type) {
            $labels = DB::table('pg_enum as e')
                ->join('pg_type as t', 't.oid', '=', 'e.enumtypid')
                ->where('t.typname', $type)
                ->orderBy('e.enumsortorder')
                ->pluck('e.enumlabel')
                ->all();

            $this->assertSame(['7', '8', '9', '10', '11', '12'], $labels, $type);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function assertInsertRejected(string $table, array $attributes): void
    {
        try {
            // Nested transaction → SAVEPOINT: the rejected statement aborts
            // only its savepoint, keeping the outer RefreshDatabase
            // transaction usable for subsequent assertions in this loop.
            DB::transaction(fn () => DB::table($table)->insert($attributes));
            $this->fail("{$table} insert was expected to be rejected at the DB level");
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                'invalid input value for enum',
                $e->getMessage()
            );
        }
    }
}
