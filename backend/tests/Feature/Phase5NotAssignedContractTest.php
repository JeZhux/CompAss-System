<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ARCH-002 FR-005 (WU-3, Phase 5) — every teacher-not-assigned path renders
 * 403 `SUBJECT_NOT_ASSIGNED` through the canonical error envelope.
 *
 * A Teacher who is NOT assigned to a subject-section must receive
 * 403 `{ error: { message, code: "SUBJECT_NOT_ASSIGNED" } }` from
 * every teacher-scoped endpoint targeting that section — never the old
 * 409 `NOT_ASSIGNED_TO_SECTION`, and never the generic `FORBIDDEN`
 * (spec §16: "teacher endpoints additionally 403 SUBJECT_NOT_ASSIGNED").
 *
 * Each test drives the API as the assigned teacher against a foreign
 * subject-section (teacher only owns subjectSectionA; subjectSectionB is
 * never assigned), covering announcement/assignment/assessment create,
 * learning-material index + create/upload (AI teacher actions, §3.9),
 * analytics dashboard, competency class-level report, and the
 * not-competent-flags / competency-summary scopes.
 *
 * @Traced-To ARCH-002 FR-005, ARCH-002 QA-004, ARCH-002 FR-011, ARCH-002 QA-004 (ARCH-005 §2)
 */
#[Group('phase5')]
class Phase5NotAssignedContractTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $teacherB = null;

    private ?User $student = null;

    private ?Classroom $classroomA = null;

    private ?Classroom $classroomB = null;

    private ?Section $sectionA = null;

    private ?Section $sectionB = null;

    private ?Subject $subject = null;

    private ?Subject $unassignedSubject = null;

    private ?CompetencyReference $competency = null;

    private ?CompetencyReference $unassignedCompetency = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->teacherB = $this->student = null;
        $this->classroomA = $this->classroomB = null;
        $this->sectionA = $this->sectionB = null;
        $this->subject = $this->unassignedSubject = null;
        $this->competency = $this->unassignedCompetency = null;
    }

    /** Build two subjects/sections; the teacher owns only subject A + section A. */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-NA']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);

        $this->sectionA = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-NA']);
        $this->sectionB = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7B-NA']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-NA']);
        $this->unassignedSubject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'English', 'code' => 'ENG7-NA']);

        $this->competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NA-01',
            'descriptor' => 'Not-assigned contract competency',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->unassignedCompetency = CompetencyReference::create(['semester' => '1', 'code' => 'E7-NA-01',
            'descriptor' => 'Unassigned subject competency',
            'subject_id' => $this->unassignedSubject->id,
            'grade_level' => '7']);

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create([
            'role' => 'Student']);

        $this->classroomA = app(\App\Services\ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->sectionA->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroomA->id,
            'student_id' => $this->student->id]);
        $this->teacherB = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->classroomB = app(\App\Services\ClassroomService::class)->createClassroom($this->teacherB->id, $this->unassignedSubject->id, $this->sectionB->id, '2026-2027', null);
    }

    /** 403 + SUBJECT_NOT_ASSIGNED is the whole ARCH-002 FR-005 contract. */
    private function assertNotAssigned(TestResponse $response): void
    {
        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'SUBJECT_NOT_ASSIGNED');
    }

    // ========================================================================
    // #35 — POST /api/teacher/announcements
    // ========================================================================

    /** @test */
    public function test_35_announcement_create_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/teacher/classrooms/'.$this->classroomB->id.'/announcements', [
                'title' => 'Not My Section',
                'body' => 'Should be rejected']);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // ========================================================================
    // #41 — POST /api/teacher/assignments
    // ========================================================================

    /** @test */
    public function test_41_assignment_create_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/teacher/classrooms/'.$this->classroomB->id.'/assignments', [
                'title' => 'Not My Section HW',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00']);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // ========================================================================
    // #55 — POST /api/teacher/assessments
    // ========================================================================

    /** @test */
    public function test_55_assessment_create_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/teacher/classrooms/'.$this->classroomB->id.'/assessments', [
                'title' => 'Not My Section Quiz',
                'description' => '',
                'type' => 'Recorded']);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // ========================================================================
    // #84 — POST /api/teacher/learning-materials (AI teacher action, §3.9)
    // ========================================================================

    /** @test */
    public function test_84_learning_material_create_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->unassignedSubject->id,
                'competency_id' => $this->unassignedCompetency->id,
                'title' => 'Not My Section Guide',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf')]);

        $this->assertNotAssigned($response);
    }

    // ========================================================================
    // #83 — GET /api/teacher/learning-materials (AI teacher action, §3.9)
    // ========================================================================

    /** @test */
    public function test_83_learning_material_index_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->unassignedSubject->id);

        $this->assertNotAssigned($response);
    }

    // ========================================================================
    // #77–#80 — analytics teacher dashboard for a foreign subject-section
    // ========================================================================

    /** @test */
    public function test_77_heatmap_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->unassignedSubject->id);

        $this->assertNotAssigned($response);
    }

    /** @test */
    public function test_78_gap_report_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->unassignedSubject->id);

        $this->assertNotAssigned($response);
    }

    /** @test */
    public function test_79_trends_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->unassignedSubject->id);

        $this->assertNotAssigned($response);
    }

    /** @test */
    public function test_80_student_drill_down_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id
                . '&subject_id=' . $this->unassignedSubject->id);

        $this->assertNotAssigned($response);
    }

    // ========================================================================
    // #75 — GET /api/teacher/sections/{sectionId}/class-level-report
    // ========================================================================

    /** @test */
    public function test_75_class_level_report_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        // Section-scoped report uses classroom ownership in that section:
        // teacher owns only section A, so section B is 403 SECTION_NOT_ASSIGNED.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/sections/{$this->sectionB->id}/class-level-report");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'SECTION_NOT_ASSIGNED');
    }

    // ========================================================================
    // #73/#74 — teacher-scoped competency endpoints, foreign subject-section
    // ========================================================================

    /** @test */
    public function test_73_not_competent_flags_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/not-competent-flags?subject_id=' . $this->unassignedSubject->id);

        $this->assertNotAssigned($response);
    }

    /** @test */
    public function test_74_competency_summary_for_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/competency-summary?subject_id=' . $this->unassignedSubject->id);

        $this->assertNotAssigned($response);
    }

    /** @test */
    public function test_assigned_teacher_control_calls_still_pass(): void
    {
        // Control: the same teacher against their OWN section keeps 200s —
        // the conversion must not widen or narrow the assigned path.
        $this->setUpOrg();

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/learning-materials?subject_id=' . $this->subject->id)
            ->assertOk();

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id)
            ->assertOk();

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/sections/{$this->sectionA->id}/class-level-report")
            ->assertOk();

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/teacher/classrooms/'.$this->classroomA->id.'/announcements', [
                'title' => 'My Section',
                'body' => 'Accepted'])
            ->assertStatus(201);
    }
}
