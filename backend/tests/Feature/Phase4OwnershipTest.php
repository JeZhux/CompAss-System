<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ARCH-002 QA-004 — service-layer ownership-scoping audit (WU-3, Phase 4).
 *
 * Negative coverage added for gaps found in the audit (the sheet lives at
 * docs/ownership-audit-sheet.md):
 *   #101/#102  teacher reading a student from a section they are NOT assigned
 *              to (GradingService::getMasteryRecord / getStudentMasterySummary —
 *              GAP fixed in this pass; cross-section read now 403).
 *   #73/#74/#78/#79  teacher scoping a foreign subject-section (403).
 * #75 (403 SUBJECT_NOT_ASSIGNED, ARCH-002 FR-005) is already covered by
 * Phase5Test::test_75_teacher_not_assigned_to_section_returns_403.
 *
 * @Traced-To ARCH-002 QA-004, ARCH-002 QA-004, ARCH-002 QA-004
 */
#[Group('phase4-ownership')]
class Phase4OwnershipTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacherA = null;
    private ?User $teacherB = null;
    private ?User $teacherUnassigned = null;
    private ?User $studentA = null;
    private ?User $studentB = null;

            private ?Classroom $classroomA = null;
    private ?Classroom $classroomB = null;
    private ?CompetencyReference $competency = null;
    private ?Subject $subject = null;
    private ?Section $sectionA = null;
    private ?Section $sectionB = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacherA = $this->teacherB = $this->teacherUnassigned = null;
        $this->studentA = $this->studentB = null;
        $this->classroomA = $this->classroomB = null;
        $this->competency = null;
        $this->subject = null;
        $this->sectionA = $this->sectionB = null;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2025-OWN']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2025-06-01',
            'end_date' => '2025-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);

        $this->sectionA = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7A-OWN']);
        $this->sectionB = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '7B-OWN']);

        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-OWN']);

        $this->competency = CompetencyReference::create(['semester' => '1', 'code' => 'M7-OWN-01',
            'descriptor' => 'Ownership audit competency',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $this->teacherA = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->teacherB = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->teacherUnassigned = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->studentA = User::factory()->create(['role' => 'Student']);
        $this->studentB = User::factory()->create(['role' => 'Student']);


        $this->classroomA = app(ClassroomService::class)->createClassroom($this->teacherA->id, $this->subject->id, $this->sectionA->id, '2026-2027', null);
        $this->classroomB = app(ClassroomService::class)->createClassroom($this->teacherB->id, $this->subject->id, $this->sectionB->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroomA->id,
            'student_id' => $this->studentA->id,
            'joined_at' => now()]);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroomB->id,
            'student_id' => $this->studentB->id,
            'joined_at' => now()]);
    }

    /** Give studentA a scored Recorded mastery record in section A (via the API flow). */
    private function createScoredSubmissionForStudentA(): void
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacherA->id,
            'classroom_id' => $this->classroomA->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->sectionA->gradeLevel->semester_id,
            'title' => 'OWN Test',
            'type' => 'Recorded',
            'status' => 'released']);

        AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competency->id,
            'sort_order' => 1]);

        Sanctum::actingAs($this->studentA);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $assessment->items()->first()->id => 'A']]);
    }

    // ========================================================================
    // #101 — GET /api/mastery/records (GradingService::getMasteryRecord)
    // ========================================================================

    /** @test */
    public function test_101_teacher_can_view_student_from_own_section_returns_200(): void
    {
        $this->setUpOrg();
        $this->createScoredSubmissionForStudentA();

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->studentA->id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /** @test */
    public function test_101_teacher_cannot_view_student_from_other_section_returns_403(): void
    {
        $this->setUpOrg();
        $this->createScoredSubmissionForStudentA();

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->studentB->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_101_teacher_with_foreign_subject_filter_returns_403(): void
    {
        $this->setUpOrg();
        $this->createScoredSubmissionForStudentA();
        $foreignSubject = Subject::create(['grade_level_id' => $this->sectionA->grade_level_id, 'name' => 'Foreign', 'code' => 'FOR7']);

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->studentA->id
                . '&subject_id=' . $foreignSubject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_101_unassigned_teacher_returns_403(): void
    {
        $this->setUpOrg();
        $this->createScoredSubmissionForStudentA();

        $response = $this->actingAs($this->teacherUnassigned, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->studentA->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    // ========================================================================
    // #102 — GET /api/mastery/records/{studentId}/summary
    //         (GradingService::getStudentMasterySummary)
    // ========================================================================

    /** @test */
    public function test_102_teacher_can_view_student_from_own_section_returns_200(): void
    {
        $this->setUpOrg();
        $this->createScoredSubmissionForStudentA();

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson("/api/mastery/records/{$this->studentA->id}/summary");

        $response->assertOk();
        $this->assertEquals($this->studentA->id, $response->json('data.student_id'));
    }

    /** @test */
    public function test_102_teacher_cannot_view_student_from_other_section_returns_403(): void
    {
        $this->setUpOrg();
        $this->createScoredSubmissionForStudentA();

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson("/api/mastery/records/{$this->studentB->id}/summary");

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_102_teacher_from_other_section_cannot_view_student_summary_returns_403(): void
    {
        $this->setUpOrg();
        $this->createScoredSubmissionForStudentA();

        $response = $this->actingAs($this->teacherB, 'sanctum')
            ->getJson("/api/mastery/records/{$this->studentA->id}/summary");

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    // ========================================================================
    // #41/#55 — create endpoints, foreign subject-section (service-level 403)
    // ========================================================================

    /** @test */
    public function test_41_teacher_creates_assignment_for_foreign_subject_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroomB->id.'/assignments', [
                'title' => 'Foreign HW',
                'description' => '',
                'due_date' => '2026-10-15 23:59:00']);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_55_teacher_creates_assessment_for_foreign_subject_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroomB->id.'/assessments', [
                'title' => 'Foreign Assessment',
                'description' => '',
                'type' => 'Recorded']);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    // ========================================================================
    // #84 — learning materials, teacher storing into a foreign subject-section
    // ========================================================================

    /** @test */
    public function test_84_teacher_stores_material_for_foreign_subject_returns_403(): void
    {
        $this->setUpOrg();
        $foreignSubject = Subject::create(['grade_level_id' => $this->sectionA->grade_level_id, 'name' => 'Foreign Sci', 'code' => 'FORSCI']);
        $foreignComp = CompetencyReference::create(['semester' => '1', 'code' => 'M7-FOR-01', 'descriptor' => 'Foreign competency', 'subject_id' => $foreignSubject->id, 'grade_level' => '7']);

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $foreignSubject->id,
                'competency_id' => $foreignComp->id,
                'title' => 'Foreign Material',
                'file' => File::fake()->create('guide.pdf', 100, 'application/pdf')]);

        $response->assertStatus(403);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }

    // ========================================================================
    // #73/#74 — teacher-scoped competency endpoints, foreign subject-section
    // ========================================================================

    /** @test */
    public function test_73_teacher_not_assigned_to_subject_returns_403(): void
    {
        $this->setUpOrg();
        $foreignSubject = Subject::create(['grade_level_id' => $this->sectionA->grade_level_id, 'name' => 'Foreign73', 'code' => 'FOR73']);

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson('/api/teacher/not-competent-flags?subject_id=' . $foreignSubject->id);

        $response->assertStatus(403);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }

    /** @test */
    public function test_74_teacher_not_assigned_to_subject_returns_403(): void
    {
        $this->setUpOrg();
        $foreignSubject = Subject::create(['grade_level_id' => $this->sectionA->grade_level_id, 'name' => 'Foreign74', 'code' => 'FOR74']);

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson('/api/teacher/competency-summary?subject_id=' . $foreignSubject->id);

        $response->assertStatus(403);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }

    // ========================================================================
    // #78/#79 — analytics endpoints, teacher scoping a foreign subject-section
    // ========================================================================

    /** @test */
    public function test_78_teacher_not_assigned_to_subject_returns_403(): void
    {
        $this->setUpOrg();
        $foreignSubject = Subject::create(['grade_level_id' => $this->sectionA->grade_level_id, 'name' => 'Foreign78', 'code' => 'FOR78']);

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $foreignSubject->id);

        $response->assertStatus(403);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }

    /** @test */
    public function test_79_teacher_not_assigned_to_subject_returns_403(): void
    {
        $this->setUpOrg();
        $foreignSubject = Subject::create(['grade_level_id' => $this->sectionA->grade_level_id, 'name' => 'Foreign79', 'code' => 'FOR79']);

        $response = $this->actingAs($this->teacherA, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $foreignSubject->id);

        $response->assertStatus(403);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }
}
