<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class U04VerificationTest extends TestCase
{
    use RefreshDatabase;

    private ?Subject $subject = null;

    private User $admin;
    private User $teacher;
    private Classroom $classroom;
    private Classroom $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-U04']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-U04']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Math-U04', 'code' => 'MATH-U04']);

        // Classroom for 2026-2027 (teacher scope derives from the classroom
        // itself since the Semester restructure — no assignment row).
        $service = app(ClassroomService::class);
        // Need Sanctum acting as teacher or admin to log? We'll create via model direct to avoid assignment check complexity but ensure audit log for creation is not needed.
        // Use service to ensure proper join_key generation
        Sanctum::actingAs($this->teacher);
        $this->classroom = $service->createClassroom($this->teacher->id, $this->subject->id, $section->id, '2026-2027', null);
        $this->assignment = $this->classroom;

        // Reset to admin for PATCH tests
        Sanctum::actingAs($this->admin);
    }

    // ===== Classroom PATCH =====

    public function test_classroom_patch_422_regex(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->patchJson('/api/admin/classrooms/' . $this->classroom->id, ['school_year' => 'bad-year']);
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('school_year', $response->json('error.fields'));
    }

    public function test_classroom_patch_422_regex_various(): void
    {
        Sanctum::actingAs($this->admin);
        foreach (['2021-22', '2025/2026', '20252026', 'abcd-efgh'] as $bad) {
            $this->patchJson('/api/admin/classrooms/' . $this->classroom->id, ['school_year' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
    }

    public function test_classroom_patch_422_consecutive(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->patchJson('/api/admin/classrooms/' . $this->classroom->id, ['school_year' => '2025-2027']);
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('school_year', $response->json('error.fields'));
        // message contains second year must be first year plus one
        $fields = $response->json('error.fields.school_year');
        $this->assertStringContainsString('plus one', implode(' ', $fields));
    }

    public function test_classroom_patch_409_duplicate(): void
    {
        Sanctum::actingAs($this->admin);
        // Create second classroom same teacher+subject+section but different year 2025-2026
        $section = Section::findOrFail($this->classroom->section_id);
        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $other = $service->createClassroom($this->teacher->id, $this->subject->id, $section->id, '2025-2026', 'Dup');
        Sanctum::actingAs($this->admin);

        // Now try to patch original 2026-2027 to 2025-2026 => duplicate
        $response = $this->patchJson('/api/admin/classrooms/' . $this->classroom->id, ['school_year' => '2025-2026']);
        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'DUPLICATE_CLASSROOM');
    }

    public function test_classroom_patch_200_success_and_audit(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->patchJson('/api/admin/classrooms/' . $this->classroom->id, ['school_year' => '2025-2026']);
        $response->assertStatus(200);
        $response->assertJsonPath('data.school_year', '2025-2026');
        $this->assertDatabaseHas('classrooms', ['id' => $this->classroom->id, 'school_year' => '2025-2026']);

        // Audit log
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'update',
            'user_id' => $this->admin->id,
            'auditable_type' => Classroom::class,
            'auditable_id' => $this->classroom->id,
        ]);
        $log = AuditLog::where('auditable_type', Classroom::class)->where('auditable_id', $this->classroom->id)->latest('id')->first();
        $this->assertStringContainsString('2026-2027 -> 2025-2026', $log->description);
        $this->assertEquals('2026-2027', $log->metadata['old_school_year']);
        $this->assertEquals('2025-2026', $log->metadata['new_school_year']);
    }

    // ===== Assignment PATCH (deprecated since the Semester restructure:
    // teacher scope derives from classrooms, so PATCH
    // /admin/teacher-assignments/{id} is a 410 GONE stub — manage the
    // school_year via PATCH /admin/classrooms/{id} instead). =====

    public function test_assignment_patch_422_regex(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->patchJson('/api/admin/teacher-assignments/' . $this->assignment->id, ['school_year' => 'bad-year']);
        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'GONE');
    }

    public function test_assignment_patch_422_consecutive(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->patchJson('/api/admin/teacher-assignments/' . $this->assignment->id, ['school_year' => '2025-2027']);
        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'GONE');
    }

    public function test_assignment_patch_409_duplicate(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->patchJson('/api/admin/teacher-assignments/' . $this->assignment->id, ['school_year' => '2025-2026']);
        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'GONE');
    }

    public function test_assignment_patch_200_success_and_audit(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->patchJson('/api/admin/teacher-assignments/' . $this->assignment->id, ['school_year' => '2024-2025']);
        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'GONE');
        // The stub performs no write: the classroom keeps its school year.
        $this->assertDatabaseHas('classrooms', ['id' => $this->assignment->id, 'school_year' => '2026-2027']);
    }

    // ===== Legacy migration idempotency simulation =====

    public function test_legacy_migration_simulation(): void
    {
        // U-04: assessments.classroom_id is NOT NULL — legacy null rows can no
        // longer exist (the legacy backfill command is removed). Classroom-bound
        // rows are created directly; a null insert must fail closed.
        $teacher2 = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $subject2 = Subject::create(['grade_level_id' => GradeLevel::first()->id, 'name' => 'Science-U04', 'code' => 'SCI-U04']);
        $section2 = Section::create(['grade_level_id' => GradeLevel::first()->id, 'name' => '7B-U04-Legacy']);

        Sanctum::actingAs($teacher2);
        $legacyClassroom = app(ClassroomService::class)->createClassroom($teacher2->id, $subject2->id, $section2->id, '2026-2027', null);

        // Need semester_id for assessments
        $semesterId = Semester::first()->id;
        DB::table('assessments')->insert([
            [
                'teacher_id' => $teacher2->id,
                'subject_id' => $subject2->id,
                'semester_id' => $semesterId,
                'classroom_id' => $legacyClassroom->id,
                'title' => 'Legacy Assessment 1',
                'description' => '',
                'type' => 'Recorded',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'teacher_id' => $teacher2->id,
                'subject_id' => $subject2->id,
                'semester_id' => $semesterId,
                'classroom_id' => $legacyClassroom->id,
                'title' => 'Legacy Assessment 2',
                'description' => '',
                'type' => 'Recorded',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // No null-classroom rows can exist under U-04 NOT NULL.
        $this->assertEquals(0, DB::table('assessments')->whereNull('classroom_id')->where('teacher_id', $teacher2->id)->count());
        $this->assertEquals(2, DB::table('assessments')->where('classroom_id', $legacyClassroom->id)->count());
        $this->assertEquals(1, Classroom::where('teacher_id', $teacher2->id)->where('subject_id', $subject2->id)->count());

        // A null classroom_id insert must fail closed at the DB layer.
        try {
            DB::table('assessments')->insert([
                'teacher_id' => $teacher2->id,
                'subject_id' => $subject2->id,
                'semester_id' => $semesterId,
                'classroom_id' => null,
                'title' => 'Legacy Assessment Null',
                'description' => '',
                'type' => 'Recorded',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException: assessments.classroom_id is NOT NULL (U-04).');
        } catch (QueryException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
        }
    }
}
