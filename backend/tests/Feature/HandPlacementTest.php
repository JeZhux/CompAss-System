<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\ClassroomEnrollmentMove;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase B hand place/move: POST /api/admin/enrollments/place takes
 * {full_name, classroom_id} only — server generates the STU- CompAss ID.
 * Move is unchanged ({classroom_id} on the enrollment).
 */
class HandPlacementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $teacher2;

    private User $student;

    private Classroom $classroomA;

    private Classroom $classroomB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['must_change_password' => false]);
        $this->teacher = User::factory()->teacher()->create(['must_change_password' => false]);
        $this->teacher2 = User::factory()->teacher()->create(['must_change_password' => false]);
        $this->student = User::factory()->student()->create(['must_change_password' => false]);

        $year = SchoolYear::create(['name' => 'SY-HP']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $sectionA = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-HP']);
        $sectionB = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7B-HP']);
        $subjectA = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Math-HP', 'code' => 'MATH-HP']);
        $subjectB = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Science-HP', 'code' => 'SCI-HP']);

        $service = app(ClassroomService::class);
        Sanctum::actingAs($this->teacher);
        $this->classroomA = $service->createClassroom($this->teacher->id, $subjectA->id, $sectionA->id, '2026-2027', null);
        Sanctum::actingAs($this->teacher2);
        $this->classroomB = $service->createClassroom($this->teacher2->id, $subjectB->id, $sectionB->id, '2026-2027', null);
    }

    public function test_place_new_learner_returns_201_with_manual_shape(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'A. Learner',
            'classroom_id' => $this->classroomA->id,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.classroom_id', $this->classroomA->id)
            ->assertJsonPath('data.display_name', 'A. Learner')
            ->assertJsonPath('data.placement_kind', 'manual')
            ->assertJsonPath('data.moved_at', null);
        $this->assertNotNull($res->json('data.id'));
        $this->assertNotNull($res->json('data.school_id'));
        $this->assertMatchesRegularExpression('/^STU-[0-9]{4}-[0-9]{5}$/', $res->json('data.school_id'));
        $this->assertNotNull($res->json('data.placed_at'));

        $schoolId = $res->json('data.school_id');
        $learner = User::where('school_id', $schoolId)->firstOrFail();
        $this->assertSame('Student', $learner->role);
        $this->assertSame('A. Learner', $learner->name);
        $this->assertDatabaseHas('classroom_enrollments', [
            'id' => $res->json('data.id'),
            'classroom_id' => $this->classroomA->id,
            'student_id' => $learner->id,
        ]);
        $this->assertDatabaseHas('classroom_enrollment_moves', [
            'classroom_id' => $this->classroomA->id,
            'student_id' => $learner->id,
            'actor_id' => $this->admin->id,
            'action' => 'placed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'create',
            'auditable_type' => ClassroomEnrollment::class,
            'auditable_id' => $res->json('data.id'),
        ]);
    }

    public function test_place_rejects_manual_ids_and_group_year(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([
            ['full_name' => 'B. Learner', 'classroom_id' => $this->classroomA->id, 'learner_code' => 'STU-0000-00000'],
            ['full_name' => 'B. Learner', 'classroom_id' => $this->classroomA->id, 'school_id' => 'STU-0000-00000'],
            ['full_name' => 'B. Learner', 'classroom_id' => $this->classroomA->id, 'group' => '7A-HP'],
            ['full_name' => 'B. Learner', 'classroom_id' => $this->classroomA->id, 'year_level' => '7'],
            ['full_name' => 'B. Learner', 'classroom_id' => $this->classroomA->id, 'id' => 999],
            // Unlisted keys (typos) fail loudly too, never silently ignored.
            ['full_name' => 'B. Learner', 'classroom_id' => $this->classroomA->id, 'classroomId' => $this->classroomA->id],
            ['full_name' => 'B. Learner', 'classroom_id' => $this->classroomA->id, 'nmae' => 'Typo Key'],
        ] as $payload) {
            $this->postJson('/api/admin/enrollments/place', $payload)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
    }

    public function test_identical_destination_move_returns_409(): void
    {
        Sanctum::actingAs($this->admin);

        $placed = $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'C. Learner',
            'classroom_id' => $this->classroomA->id,
        ]);
        $placed->assertStatus(201);
        $enrollmentId = $placed->json('data.id');

        $movesBefore = ClassroomEnrollmentMove::count();

        $this->postJson("/api/admin/enrollments/{$enrollmentId}/move", [
            'classroom_id' => $this->classroomA->id,
        ])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_PLACED');

        $this->assertSame($movesBefore, ClassroomEnrollmentMove::count());
        $this->assertSame($this->classroomA->id, ClassroomEnrollment::findOrFail($enrollmentId)->classroom_id);
    }

    public function test_archived_destination_place_and_move_return_410(): void
    {
        app(ClassroomService::class)->adminArchive($this->classroomB->id);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'D. Learner',
            'classroom_id' => $this->classroomB->id,
        ])->assertStatus(410)->assertJsonPath('error.code', 'CLASSROOM_ARCHIVED');

        $placed = $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'E. Learner',
            'classroom_id' => $this->classroomA->id,
        ]);
        $placed->assertStatus(201);

        $this->postJson("/api/admin/enrollments/{$placed->json('data.id')}/move", [
            'classroom_id' => $this->classroomB->id,
        ])->assertStatus(410)->assertJsonPath('error.code', 'CLASSROOM_ARCHIVED');
    }

    public function test_move_returns_200_with_message_and_appends_history(): void
    {
        Sanctum::actingAs($this->admin);

        $placed = $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'F. Learner',
            'classroom_id' => $this->classroomA->id,
        ]);
        $placed->assertStatus(201);
        $enrollmentId = $placed->json('data.id');
        $schoolId = $placed->json('data.school_id');

        $moved = $this->postJson("/api/admin/enrollments/{$enrollmentId}/move", [
            'classroom_id' => $this->classroomB->id,
        ]);

        $moved->assertOk()
            ->assertJsonPath('data.id', $enrollmentId)
            ->assertJsonPath('data.classroom_id', $this->classroomB->id)
            ->assertJsonPath('data.school_id', $schoolId)
            ->assertJsonPath('data.display_name', 'F. Learner')
            ->assertJsonPath('data.placement_kind', 'manual');
        $this->assertNotNull($moved->json('data.message'));
        $this->assertNotNull($moved->json('data.moved_at'));

        $learner = User::where('school_id', $schoolId)->firstOrFail();
        $this->assertSame($this->classroomB->id, ClassroomEnrollment::findOrFail($enrollmentId)->classroom_id);

        $history = ClassroomEnrollmentMove::where('student_id', $learner->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame('placed', $history[0]->action);
        $this->assertSame('moved', $history[1]->action);
        $this->assertSame($this->admin->id, (int) $history[1]->actor_id);
        $this->assertSame($this->classroomB->id, (int) $history[1]->classroom_id);
        $this->assertSame($this->classroomA->id, (int) $history[1]->source_classroom_id);

        $audit = $this->getJson('/api/admin/audit-logs/entity?' . http_build_query([
            'auditable_type' => ClassroomEnrollment::class,
            'auditable_id' => $enrollmentId,
        ]));
        $audit->assertOk();
        $this->assertGreaterThanOrEqual(2, count($audit->json('data')));
    }

    public function test_manager_only_and_password_gate(): void
    {
        $payload = [
            'full_name' => 'G. Learner',
            'classroom_id' => $this->classroomA->id,
        ];

        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/admin/enrollments/place', $payload)
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        Sanctum::actingAs($this->student);
        $this->postJson('/api/admin/enrollments/place', $payload)
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        $gatedAdmin = User::factory()->admin()->create(['must_change_password' => true]);
        Sanctum::actingAs($gatedAdmin);
        $this->postJson('/api/admin/enrollments/place', $payload)
            ->assertStatus(403)->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_guest_is_unauthenticated(): void
    {
        Auth::guard('sanctum')->forgetUser();

        $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'G. Learner',
            'classroom_id' => $this->classroomA->id,
        ])->assertStatus(401);
    }

    public function test_blank_full_name_and_supplied_id_return_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/enrollments/place', [
            'full_name' => '',
            'classroom_id' => $this->classroomA->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->postJson('/api/admin/enrollments/place', [
            'id' => 999,
            'full_name' => 'H. Learner',
            'classroom_id' => $this->classroomA->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_unknown_classroom_and_enrollment_return_404(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'I. Learner',
            'classroom_id' => 999999,
        ])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $this->postJson('/api/admin/enrollments/999999/move', [
            'classroom_id' => $this->classroomB->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_place_and_move_reject_state_and_role_keys(): void
    {
        Sanctum::actingAs($this->admin);

        // Place is {full_name, classroom_id} only: state/role keys fail
        // loudly, never silently ignored.
        foreach ([
            ['full_name' => 'J. Learner', 'classroom_id' => $this->classroomA->id, 'is_active' => false],
            ['full_name' => 'J. Learner', 'classroom_id' => $this->classroomA->id, 'role' => 'Teacher'],
            ['full_name' => 'J. Learner', 'classroom_id' => $this->classroomA->id, 'must_change_password' => false],
            ['full_name' => 'J. Learner', 'classroom_id' => $this->classroomA->id, 'status' => 'inactive'],
        ] as $payload) {
            $this->postJson('/api/admin/enrollments/place', $payload)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        $this->assertDatabaseMissing('users', ['name' => 'J. Learner']);

        $placed = $this->postJson('/api/admin/enrollments/place', [
            'full_name' => 'K. Learner',
            'classroom_id' => $this->classroomA->id,
        ])->assertStatus(201);
        $enrollmentId = $placed->json('data.id');

        // Move is {classroom_id} only: name/state keys fail loudly and the
        // enrollment stays put.
        foreach ([
            ['classroom_id' => $this->classroomB->id, 'full_name' => 'K. Learner'],
            ['classroom_id' => $this->classroomB->id, 'is_active' => false],
            ['classroom_id' => $this->classroomB->id, 'role' => 'Teacher'],
            // Unlisted keys (typos) fail loudly too, never silently ignored.
            ['classroom_id' => $this->classroomB->id, 'destination' => $this->classroomB->id],
            ['classroom_id' => $this->classroomB->id, 'classroomId' => $this->classroomB->id],
        ] as $payload) {
            $this->postJson("/api/admin/enrollments/{$enrollmentId}/move", $payload)
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
        $this->assertSame($this->classroomA->id, ClassroomEnrollment::findOrFail($enrollmentId)->classroom_id);
    }
}
