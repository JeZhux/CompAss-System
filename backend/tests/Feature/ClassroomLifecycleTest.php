<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassroomLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;
    private User $teacher2;
    private User $student;
    private User $student2;
        private Section $section;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->teacher2 = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $this->student2 = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-LC']);
        $semester = \App\Models\Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $this->section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-LC']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Math-LC', 'code' => 'MATH-LC']);
        
    }

    private function createClassroomForTeacher(User $teacher, string $schoolYear = '2026-2027', ?string $suffix = null): Classroom
    {
        $service = app(ClassroomService::class);
        Sanctum::actingAs($teacher);
        return $service->createClassroom($teacher->id, $this->subject->id, $this->section->id, $schoolYear, $suffix);
    }

    public function test_join_first_201_second_200_idempotent(): void
    {
        $classroom = $this->createClassroomForTeacher($this->teacher);
        Sanctum::actingAs($this->student);
        $res1 = $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key]);
        $res1->assertStatus(201)->assertJsonPath('data.already_joined', false);
        $res2 = $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key]);
        $res2->assertStatus(200)->assertJsonPath('data.already_joined', true);
        // normalize check: lower + dash + spaces
        Sanctum::actingAs($this->student2);
        $display = substr($classroom->join_key, 0, 2).'-'.substr($classroom->join_key, 2);
        $res3 = $this->postJson('/api/classrooms/join', ['key' => strtolower($display).' ']);
        $res3->assertStatus(201);
        $res4 = $this->postJson('/api/classrooms/join', ['key' => ' '.strtolower($classroom->join_key).' ']);
        $res4->assertStatus(200);
    }

    public function test_old_key_410_gone_unknown_404(): void
    {
        $classroom = $this->createClassroomForTeacher($this->teacher);
        $oldKey = $classroom->join_key;
        // reset key as teacher
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$classroom->id.'/reset-key')->assertOk();
        $classroom->refresh();
        $newKey = $classroom->join_key;
        $this->assertNotEquals($oldKey, $newKey);
        $this->assertDatabaseHas('classroom_join_key_history', ['classroom_id' => $classroom->id, 'old_join_key' => $oldKey]);

        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $oldKey])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        // display form also 410
        $oldDisplay = substr($oldKey, 0, 2).'-'.substr($oldKey, 2);
        $this->postJson('/api/classrooms/join', ['key' => $oldDisplay])->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        // unknown never issued -> 404
        $this->postJson('/api/classrooms/join', ['key' => 'ZZZZZZ'])->assertStatus(404)->assertJsonPath('error.code', 'KEY_INVALID');
        // new key still works 201
        $this->postJson('/api/classrooms/join', ['key' => $newKey])->assertStatus(201);
    }

    public function test_disabled_join_returns_410(): void
    {
        $classroom = $this->createClassroomForTeacher($this->teacher);
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$classroom->id.'/toggle-join', ['is_join_enabled' => false])->assertOk()->assertJsonPath('data.is_join_enabled', false);
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key])->assertStatus(410)->assertJsonPath('error.code', 'CLASSROOM_JOIN_DISABLED');
        // re-enable -> succeeds
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$classroom->id.'/toggle-join', ['is_join_enabled' => true])->assertOk();
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key])->assertStatus(201);
    }

    public function test_archived_returns_410(): void
    {
        $classroom = $this->createClassroomForTeacher($this->teacher);
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$classroom->id.'/archive')->assertOk();
        $classroom->refresh();
        $this->assertNotNull($classroom->archived_at);
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key])->assertStatus(410)->assertJsonPath('error.code', 'CLASSROOM_ARCHIVED');
        // unarchive -> succeeds
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$classroom->id.'/unarchive')->assertOk();
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key])->assertStatus(201);
    }

    public function test_leave_idempotent(): void
    {
        $classroom = $this->createClassroomForTeacher($this->teacher);
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $classroom->join_key])->assertStatus(201);
        // first leave
        $this->postJson('/api/student/classrooms/'.$classroom->id.'/leave')->assertOk()->assertJsonPath('data.message', 'Left classroom.')->assertJsonPath('data.already_left', false);
        $this->assertDatabaseMissing('classroom_enrollments', ['classroom_id' => $classroom->id, 'student_id' => $this->student->id]);
        // second leave idempotent still 200
        $this->postJson('/api/student/classrooms/'.$classroom->id.'/leave')->assertOk()->assertJsonPath('data.already_left', true);
        // leave when never joined is refused (ARCH-005 block 4.20: no current or prior enrollment)
        Sanctum::actingAs($this->student2);
        $this->postJson('/api/student/classrooms/'.$classroom->id.'/leave')->assertStatus(403)->assertJsonPath('error.code', 'NOT_ENROLLED');
    }

    public function test_per_teacher_uniqueness(): void
    {
        $c1 = $this->createClassroomForTeacher($this->teacher, '2026-2027', 'A');
        // same teacher duplicate same triple -> 409
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms', ['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'school_year' => '2026-2027'])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_CLASSROOM');
        // different teacher same triple succeeds
        Sanctum::actingAs($this->teacher2);
        $this->postJson('/api/teacher/classrooms', ['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'school_year' => '2026-2027', 'suffix' => 'B'])->assertStatus(201);
        // same teacher different year succeeds
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms', ['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'school_year' => '2025-2026'])->assertStatus(201);
    }

    public function test_create_allows_any_teacher_without_assignment_gate(): void
    {
        // Teacher scope derives from the classroom itself — no prior
        // assignment row is required to create a classroom.
        $teacher3 = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        Sanctum::actingAs($teacher3);
        $this->postJson('/api/teacher/classrooms', ['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'school_year' => '2026-2027'])->assertStatus(201);
    }

    public function test_audit_log_on_create_and_join_and_reset(): void
    {
        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $c = $service->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'create', 'auditable_type' => Classroom::class, 'auditable_id' => $c->id]);
        Sanctum::actingAs($this->student);
        $this->postJson('/api/classrooms/join', ['key' => $c->join_key])->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'create', 'auditable_type' => Classroom::class, 'auditable_id' => $c->id, 'description' => 'Student joined classroom: '.$c->name]);
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/teacher/classrooms/'.$c->id.'/reset-key')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'update', 'auditable_type' => Classroom::class, 'auditable_id' => $c->id]);
    }

    public function test_join_throttle_per_student_not_ip(): void
    {
        $c = $this->createClassroomForTeacher($this->teacher);
        // Exhaust throttle for student1 (20/min)
        Sanctum::actingAs($this->student);
        for ($i = 0;$i < 20;$i++) {
            $this->postJson('/api/classrooms/join', ['key' => 'ZZZZZZ'])->assertStatus(404);
        }
        $this->postJson('/api/classrooms/join', ['key' => 'ZZZZZZ'])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
        // student2 not blocked
        Sanctum::actingAs($this->student2);
        $this->postJson('/api/classrooms/join', ['key' => $c->join_key])->assertStatus(201);
        // also test IP rotation does not bypass: use withServerVariables REMOTE_ADDR different but same user still throttled
        Sanctum::actingAs($this->student);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->postJson('/api/classrooms/join', ['key' => 'ZZZZZZ'])->assertStatus(429);
    }
}
