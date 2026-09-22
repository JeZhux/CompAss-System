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
use App\Support\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class U14TeacherAssignmentProvisionalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $teacher;
    private User $teacher2;
    private Section $section;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false, 'name' => 'Alice Wonderland']);
        $this->teacher2 = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false, 'name' => 'Bob Builder']);

        $year = SchoolYear::create(['name' => 'SY-U14']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id, 'name' => 'Semester 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);
        $this->section = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-U14']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics-U14', 'code' => 'MATH-U14']);
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    // ===== GET teacher-assignments =====

    public function test_get_teacher_assignments_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/admin/teacher-assignments')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_get_teacher_assignments_forbidden_for_teacher(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/admin/teacher-assignments')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_get_teacher_assignments_empty_returns_paginated(): void
    {
        $this->actAsAdmin();
        $res = $this->getJson('/api/admin/teacher-assignments')->assertOk();
        $res->assertJsonPath('data', []);
        $res->assertJsonPath('meta.total', 0);
        $res->assertJsonStructure(['data', 'meta' => ['current_page','per_page','total','last_page']]);
    }

    public function test_get_teacher_assignments_filtered_and_paginated(): void
    {
        // Teacher scope derives from classrooms (no assignment rows since
        // the Semester restructure): two classrooms share one subject.
        Sanctum::actingAs($this->teacher);
        $service = app(ClassroomService::class);
        $service->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        Sanctum::actingAs($this->teacher2);
        $service->createClassroom($this->teacher2->id, $this->subject->id, $this->section->id, '2025-2026', null);
        $this->actAsAdmin();
        // filter by teacher_id
        $this->getJson('/api/admin/teacher-assignments?teacher_id='.$this->teacher->id)->assertOk()->assertJsonCount(1, 'data');
        // filter by subject_id
        $this->getJson('/api/admin/teacher-assignments?subject_id='.$this->subject->id)->assertOk()->assertJsonPath('meta.total', 2);
        // filter by school_year
        $this->getJson('/api/admin/teacher-assignments?school_year=2026-2027')->assertOk()->assertJsonCount(1, 'data');
        // pagination per_page clamp
        $this->getJson('/api/admin/teacher-assignments?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 2);
    }

    public function test_get_teacher_assignments_search_ilike_fallback(): void
    {
        Sanctum::actingAs($this->teacher);
        app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        $this->actAsAdmin();
        // search lowercase should match Alice (ILIKE / case-insensitive)
        $res = $this->getJson('/api/admin/teacher-assignments?search=alice')->assertOk();
        $this->assertEquals(1, $res->json('meta.total'));
        // search by subject code case-insensitive
        $res2 = $this->getJson('/api/admin/teacher-assignments?search=math-u14')->assertOk();
        $this->assertEquals(1, $res2->json('meta.total'));
        // search by section name
        $res3 = $this->getJson('/api/admin/teacher-assignments?search=7a-u14')->assertOk();
        $this->assertEquals(1, $res3->json('meta.total'));
        // search by teacher CompAss ID (school_id) case-insensitive
        $res4 = $this->getJson('/api/admin/teacher-assignments?search=' . urlencode($this->teacher->school_id))->assertOk();
        $this->assertEquals(1, $res4->json('meta.total'));
        // search by teacher CompAss ID lowercase still matches (IDs are uppercase)
        $res5 = $this->getJson('/api/admin/teacher-assignments?search=' . urlencode(strtolower($this->teacher->school_id)))->assertOk();
        $this->assertEquals(1, $res5->json('meta.total'));
        // legacy email search matches nothing (email vocab removed)
        $res6 = $this->getJson('/api/admin/teacher-assignments?search=ALICE@EXAMPLE.COM')->assertOk();
        $this->assertEquals(0, $res6->json('meta.total'));
    }

    public function test_get_teacher_assignments_search_no_match_returns_empty(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/admin/teacher-assignments?search=nonexistentxyz')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ===== POST teacher-assignments (deprecated since the Semester
    // restructure: teacher scope derives from classrooms, so POST
    // /admin/teacher-assignments is a 410 GONE stub — create a classroom via
    // the classroom endpoints instead). =====

    public function test_post_teacher_assignment_unauthenticated_401(): void
    {
        $this->postJson('/api/admin/teacher-assignments', [])->assertStatus(401);
    }

    public function test_post_teacher_assignment_validation_missing_fields(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/admin/teacher-assignments', []);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_post_teacher_assignment_validation_school_year_regex(): void
    {
        $this->actAsAdmin();
        foreach (['bad-year', '2025/2026', '20252026', '2021-22', 'abcd-efgh'] as $bad) {
            $res = $this->postJson('/api/admin/teacher-assignments', [
                'subject_id' => $this->subject->id,
                'teacher_id' => $this->teacher->id,
                'school_year' => $bad]);
            $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        }
    }

    public function test_post_teacher_assignment_validation_consecutive(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_year' => '2025-2027']);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_post_teacher_assignment_assignee_not_teacher_409(): void
    {
        $this->actAsAdmin();
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $student->id,
            'school_year' => '2026-2027']);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_post_teacher_assignment_admin_not_teacher_409(): void
    {
        $this->actAsAdmin();
        $admin2 = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $admin2->id,
            'school_year' => '2026-2027']);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_post_teacher_assignment_duplicate_409(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_year' => '2026-2027']);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_post_teacher_assignment_success_default_school_year_and_audit(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            // omit school_year -> defaults
        ]);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        // The stub performs no write: no classroom is created.
        $this->assertDatabaseMissing('classrooms', ['teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id]);
    }

    public function test_post_teacher_assignment_success_explicit_school_year(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_year' => '2024-2025']);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_post_teacher_assignment_nonexistent_subject_422(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => 999999,
            'teacher_id' => $this->teacher->id,
            'school_year' => '2026-2027']);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_post_teacher_assignment_nonexistent_teacher_422(): void
    {
        $this->actAsAdmin();
        $res = $this->postJson('/api/admin/teacher-assignments', [
            'subject_id' => $this->subject->id,
            'teacher_id' => 999999,
            'school_year' => '2026-2027']);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    // ===== DELETE teacher-assignments (deprecated: 410 GONE stub) =====

    public function test_delete_teacher_assignment_unauthenticated_401(): void
    {
        Classroom::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'school_year' => '2026-2027',
            'name' => '7A-U14 Mathematics-U14',
            'join_key' => 'U14A01',
            'is_join_enabled' => true]);
        $this->deleteJson('/api/admin/teacher-assignments/1')->assertStatus(401);
    }

    public function test_delete_teacher_assignment_not_found_404(): void
    {
        $this->actAsAdmin();
        $this->deleteJson('/api/admin/teacher-assignments/999999')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_delete_teacher_assignment_409_if_classroom_exists(): void
    {
        Sanctum::actingAs($this->teacher);
        $classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        $this->actAsAdmin();
        $res = $this->deleteJson('/api/admin/teacher-assignments/'.$classroom->id);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        // The stub performs no delete: the classroom survives.
        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);
    }

    public function test_delete_teacher_assignment_success_and_audit(): void
    {
        $this->actAsAdmin();
        Sanctum::actingAs($this->teacher);
        $classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        $this->actAsAdmin();
        $res = $this->deleteJson('/api/admin/teacher-assignments/'.$classroom->id);
        $res->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);
    }

    // ===== Subject-sections dropdown (deprecated since the Semester
    // restructure: the subject_sections table was dropped, so GET
    // /api/admin/subject-sections is a 410 GONE stub — list subjects via
    // GET /api/admin/subjects and classrooms via GET /api/admin/classrooms). =====

    public function test_subject_sections_unauthenticated_401(): void
    {
        $this->getJson('/api/admin/subject-sections')->assertStatus(401);
    }

    public function test_subject_sections_forbidden_for_teacher(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/admin/subject-sections')->assertStatus(403);
    }

    public function test_subject_sections_dropdown_is_gone_410(): void
    {
        $this->actAsAdmin();
        $res = $this->getJson('/api/admin/subject-sections')->assertStatus(410);
        $res->assertJsonPath('error.code', 'GONE');
    }

    public function test_subject_sections_search_is_gone_410(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/admin/subject-sections?search=mathematics')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->getJson('/api/admin/subject-sections?search=MATH-U14')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->getJson('/api/admin/subject-sections?search=7a-u14')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->getJson('/api/admin/subject-sections?search=nonexistentzzz')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_subject_sections_filters_are_gone_410(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/admin/subject-sections?subject_id='.$this->subject->id)->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->getJson('/api/admin/subject-sections?section_id='.$this->section->id)->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->getJson('/api/admin/subject-sections?subject_id=999999')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    public function test_subject_sections_pagination_is_gone_410(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/admin/subject-sections?per_page=1')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
        $this->getJson('/api/admin/subject-sections?per_page=0')->assertStatus(410)->assertJsonPath('error.code', 'GONE');
    }

    // ===== Route existence report =====

    public function test_route_list_contains_expected_admin_routes(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($uri) => str_contains($uri, 'admin/teacher-assignments') || str_contains($uri, 'admin/subject-sections'))
            ->values()->all();
        $this->assertContains('api/admin/teacher-assignments', $routes);
        $this->assertContains('api/admin/subject-sections', $routes);
        $this->assertContains('api/admin/teacher-assignments/{id}', $routes);
    }
}
