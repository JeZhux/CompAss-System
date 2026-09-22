<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AssessmentSubmission;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 *
 * Access-control + error-envelope matrix for Phase 5 + Phase 4 mastery endpoints.
 *
 * Routes (verified from routes/api.php):
 *   #73  GET /api/teacher/not-competent-flags?subject_id= — Teacher
 *   #74  GET /api/teacher/competency-summary?subject_id= — Teacher
 *   #75  GET /api/teacher/sections/{sectionId}/class-level-report — Teacher
 *   #76  GET /api/admin/competency-summary?subject_id= — Admin
 *   #100 POST /api/grades/manual — Teacher (finalize subjective)
 *   #101 GET /api/mastery/records?student_id=&competency_id=&subject_id= — Teacher/Student/Admin
 *   #102 GET /api/mastery/records/{studentId}/summary — Teacher/Student/Admin
 *   #103 POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit — Teacher
 *   #104 POST /api/grades/bulk — Teacher
 *   #65  POST /api/teacher/assessments/{id}/release-results — Teacher
 *
 * Middleware stack: auth:sanctum → password.change.required → role
 * Error codes (bootstrap/app.php):
 *   401 AuthException → UNAUTHENTICATED
 *   403 AuthorizationException → FORBIDDEN
 *   403 PasswordChangeRequired → PASSWORD_CHANGE_REQUIRED
 *   404 ModelNotFoundException → NOT_FOUND
 *   409 BusinessRuleConflictException → custom code (e.g. ASSESSMENT_NOT_RELEASED, NOT_PENDING_GRADING)
 *   422 ValidationException → VALIDATION_ERROR
 */
#[Group('phase5-access')]
class Phase5AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;
    private ?User $student = null;
    private ?User $otherStudent = null;
    private ?User $admin = null;
    private ?User $otherTeacher = null;
    private ?User $mustChangeTeacher = null;
    private ?User $mustChangeAdmin = null;

    private ?Section $section = null;
    private ?Subject $subject = null;
    private ?CompetencyReference $competencyTag1 = null;
    private ?Classroom $classroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->student = $this->otherStudent = $this->admin = null;
        $this->otherTeacher = $this->mustChangeTeacher = $this->mustChangeAdmin = null;
        $this->subject = $this->section = $this->competencyTag1 = null;
        $this->classroom = null;
    }

    protected function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2025-ACC']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2025-06-01',
            'end_date' => '2025-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-ACC']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-ACC']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-ALG-01',
            'descriptor' => 'Linear equations',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
    }

    private function ensureAllUsers(): void
    {
        $this->setUpOrg();

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->otherTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create(['role' => 'Student']);
        $this->otherStudent = User::factory()->create(['role' => 'Student']);
        $this->admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);
        $this->mustChangeTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => true]);
        $this->mustChangeAdmin = User::factory()->create(['role' => 'Admin', 'must_change_password' => true]);


        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id]);
    }

    private function createReleasedAssessment(string $type = 'Recorded', int $itemCount = 1): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'ACC Test',
            'type' => $type,
            'status' => 'released']);

        for ($i = 1; $i <= $itemCount; $i++) {
            AssessmentItem::create([
                'assessment_id' => $assessment->id,
                'item_type' => 'multiple_choice',
                'prompt' => "Q{$i}",
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id,
                'sort_order' => $i]);
        }

        return $assessment->fresh();
    }

    /** Create a submission with auto-scored objective items (100%). */
    private function createScoredSubmission(): AssessmentSubmission
    {
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'A']]);

        return AssessmentSubmission::where('assessment_id', $assessment->id)
            ->where('status', 'scored')
            ->first();
    }

    // ========================================================================
    // #73 — GET /api/teacher/not-competent-flags
    // ========================================================================

    /** @test */
    public function test_73_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/teacher/not-competent-flags');

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_73_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher/not-competent-flags');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_73_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/not-competent-flags');

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_73_returns_403_when_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/teacher/not-competent-flags');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_73_returns_404_when_nonexistent_subject()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/not-competent-flags?subject_id=999999');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_73_returns_empty_data_when_teacher_has_no_assignments()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->getJson('/api/teacher/not-competent-flags');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    // ========================================================================
    // #74 — GET /api/teacher/competency-summary
    // ========================================================================

    /** @test */
    public function test_74_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/teacher/competency-summary');

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_74_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher/competency-summary');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_74_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/competency-summary');

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_74_returns_404_when_nonexistent_subject()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/competency-summary?subject_id=999999');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_74_returns_empty_data_when_no_mastery_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/competency-summary?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    // ========================================================================
    // #75 — GET /api/teacher/sections/{sectionId}/class-level-report
    // ========================================================================

    /** @test */
    public function test_75_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson(
            "/api/teacher/sections/{$this->section->id}/class-level-report"
        );

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_75_returns_403_when_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/teacher/sections/{$this->section->id}/class-level-report");

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_75_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/teacher/sections/{$this->section->id}/class-level-report");

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_75_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson("/api/teacher/sections/{$this->section->id}/class-level-report");

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_75_returns_404_when_nonexistent_section()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/sections/99999/class-level-report');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_75_returns_200_with_report_structure()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/teacher/sections/{$this->section->id}/class-level-report");

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'section_id',
            'section_name',
            'total_students',
            'generated_at',
            'competency_reports']]);
    }

    // ========================================================================
    // #76 — GET /api/admin/competency-summary
    // ========================================================================

    /** @test */
    public function test_76_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/admin/competency-summary');

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_76_returns_403_when_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/admin/competency-summary');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_76_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeAdmin, 'sanctum')
            ->getJson('/api/admin/competency-summary');

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_76_returns_empty_when_nonexistent_subject()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/competency-summary?subject_id=999999');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    /** @test */
    public function test_76_returns_empty_data_when_no_mastery_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/admin/competency-summary');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    /** @test */
    public function test_76_supports_grade_level_filter()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $gradeLevelId = $this->section->grade_level_id;

        $response = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/competency-summary?grade_level_id={$gradeLevelId}");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /** @test */
    public function test_76_supports_term_filter()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $termId = $this->section->gradeLevel->semester_id;

        $response = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/competency-summary?semester_id={$termId}");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    // ========================================================================
    // #101 — GET /api/mastery/records
    // ========================================================================

    /** @test */
    public function test_101_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/mastery/records');

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_101_returns_403_when_must_change_password_student()
    {
        $this->ensureAllUsers();

        $mustChangeStudent = User::factory()->create([
            'role' => 'Student', 'must_change_password' => true]);

        $response = $this->actingAs($mustChangeStudent, 'sanctum')
            ->getJson('/api/mastery/records');

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_101_student_can_view_own_records()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->student->id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /** @test */
    public function test_101_student_cannot_view_other_student_records()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->otherStudent->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_101_teacher_can_view_any_student_records()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->student->id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /** @test */
    public function test_101_admin_can_view_any_student_records()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->student->id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /** @test */
    public function test_101_returns_empty_array_when_nonexistent_student_no_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/mastery/records?student_id=999999');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    /** @test */
    public function test_101_returns_empty_array_when_no_mastery_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->student->id);

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data'));
    }

    /** @test */
    public function test_101_defaults_to_authenticated_user_when_no_student_id()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/mastery/records');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    /** @test */
    public function test_101_competency_filter_returns_only_matching_competency()
    {
        $this->ensureAllUsers();

        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'A']]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/mastery/records?student_id=' . $this->student->id . '&competency_id=' . $this->competencyTag1->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->competencyTag1->id, $response->json('data.0.competency_id'));
    }

    // ========================================================================
    // #102 — GET /api/mastery/records/{studentId}/summary
    // ========================================================================

    /** @test */
    public function test_102_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson("/api/mastery/records/{$this->student->id}/summary");

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_102_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $mustChangeStudent = User::factory()->create([
            'role' => 'Student', 'must_change_password' => true]);

        $response = $this->actingAs($mustChangeStudent, 'sanctum')
            ->getJson("/api/mastery/records/{$this->student->id}/summary");

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_102_student_can_view_own_summary()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/mastery/records/{$this->student->id}/summary");

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'student_id',
            'competencies',
            'overall_level',
            'last_updated']]);
    }

    /** @test */
    public function test_102_student_cannot_view_other_student_summary()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/mastery/records/{$this->otherStudent->id}/summary");

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_102_teacher_can_view_any_summary()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/mastery/records/{$this->student->id}/summary");

        $response->assertOk();
        $this->assertEquals($this->student->id, $response->json('data.student_id'));
    }

    /** @test */
    public function test_102_admin_can_view_any_summary()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/mastery/records/{$this->student->id}/summary");

        $response->assertOk();
    }

    /** @test */
    public function test_102_returns_404_when_nonexistent_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/mastery/records/999999/summary');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_102_summary_shows_not_yet_computed_when_no_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/mastery/records/{$this->student->id}/summary");

        $response->assertOk();
        $this->assertEquals('Not Yet Computed', $response->json('data.overall_level'));
        $this->assertCount(0, $response->json('data.competencies'));
    }

    // ========================================================================
    // #100 — POST /api/grades/manual (teacher manual grading)
    // ========================================================================

    /** @test */
    public function test_100_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->postJson('/api/grades/manual', []);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_100_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->postJson('/api/grades/manual', []);

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_100_returns_403_when_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/grades/manual', []);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_100_returns_403_when_non_owner_teacher()
    {
        // Essay item keeps the attempt in pending_grading so recordManualGrade
        // reaches the ownership check (403) before the status check (409).
        $this->ensureAllUsers();

        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released']);
        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'essay',
            'prompt' => 'Explain.',
            'max_points' => 10,
            'correct_answer' => null,
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        // Student submits essay → stays pending_grading.
        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'Student essay.']]);

        $attempt = AssessmentAttempt::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10]]]);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_100_returns_409_when_attempt_not_pending_grading()
    {
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        // Objective-only assessment → auto-scored on submit → attempt is 'scored'.
        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'A']]);

        $submission = AssessmentSubmission::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $submission->attempt_id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10]]]);

        $response->assertStatus(409);
        $this->assertEquals('NOT_PENDING_GRADING', $response->json('error.code'));
    }

    /** @test */
    public function test_100_draft_mode_does_not_trigger_mastery_recompute()
    {
        $this->ensureAllUsers();

        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        // ARCH-002 FR-018: the manual-grade path is essay-only — the fixture must be
        // an essay item for this flow to reach the draft write.
        $item->update(['item_type' => 'essay', 'correct_answer' => null]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'pending_grading',
            'response_history' => []]);
        AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => true,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 5, 'max_score' => 10]]]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');

        $this->assertDatabaseMissing('mastery_records', [
            'student_id' => $this->student->id,
            'competency_id' => $this->competencyTag1->id]);
    }

    /** @test */
    public function test_100_validation_error_score_exceeds_maximum()
    {
        $this->ensureAllUsers();

        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        // ARCH-002 FR-018: the manual-grade path is essay-only — the fixture must be
        // an essay item so the score-bounds check is what fires.
        $item->update(['item_type' => 'essay', 'correct_answer' => null]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'pending_grading',
            'response_history' => []]);
        AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 15, 'max_score' => 10]]]);

        // recordManualGrade throws ValidationException for score > max_score → 422.
        $response->assertStatus(422);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
    }

    // ========================================================================
    // #103 — POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit
    // ========================================================================

    /** @test */
    public function test_103_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->postJson('/api/assessments/1/attempts/1/resubmit', [
            'reason' => 'Need more practice.']);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_103_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->postJson('/api/assessments/1/attempts/1/resubmit', [
                'reason' => 'test']);

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_103_returns_403_when_non_owner_teacher()
    {
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'A']]);

        $attempt = AssessmentAttempt::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->postJson("/api/assessments/{$assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Try again.']);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_103_returns_409_when_attempt_not_scored()
    {
        $this->ensureAllUsers();

        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();
        AssessmentItem::where('id', $item->id)->update(['item_type' => 'essay']);

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'Student writes something.']]);

        $attempt = AssessmentAttempt::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/assessments/{$assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Try again.']);

        $response->assertStatus(409);
        $this->assertEquals('NOT_SCORED', $response->json('error.code'));
    }

    /** @test */
    public function test_103_returns_409_when_student_already_has_in_progress_attempt()
    {
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'A']]);

        $scoredAttempt = AssessmentAttempt::where('assessment_id', $assessment->id)->first();

        AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'response_history' => []]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/assessments/{$assessment->id}/attempts/{$scoredAttempt->id}/resubmit", [
                'reason' => 'Try again.']);

        $response->assertStatus(409);
        $this->assertEquals('STUDENT_HAS_ACTIVE_ATTEMPT', $response->json('error.code'));
    }

    /** @test */
    public function test_103_successful_resubmit_creates_new_in_progress_attempt()
    {
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'A']]);

        $scoredAttempt = AssessmentAttempt::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/assessments/{$assessment->id}/attempts/{$scoredAttempt->id}/resubmit", [
                'reason' => 'Try again.']);

        $response->assertOk();
        $this->assertTrue($response->json('data.new_attempt_id') > $scoredAttempt->id);
        $this->assertEquals(
            'in_progress',
            AssessmentAttempt::findOrFail($response->json('data.new_attempt_id'))->status
        );
    }

    // ========================================================================
    // #104 — POST /api/grades/bulk
    // ========================================================================

    /** @test */
    public function test_104_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->postJson('/api/grades/bulk', []);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_104_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->postJson('/api/grades/bulk', []);

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_104_returns_403_when_non_owner_teacher()
    {
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->postJson('/api/grades/bulk', [
                'assessment_id' => $assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10]]]]]);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_104_partial_success_reports_errors()
    {
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();
        AssessmentItem::where('id', $item->id)->update(['item_type' => 'essay']);

        $attempt1 = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'pending_grading',
            'response_history' => []]);
        AssessmentSubmission::create([
            'attempt_id' => $attempt1->id,
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/grades/bulk', [
                'assessment_id' => $assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10]]],
                    [
                        'student_id' => $this->otherStudent->id,
                        'grade_entries' => [
                            ['assessment_item_id' => $item->id, 'score' => 5, 'max_score' => 10]]]]]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals(2, $data['total_graded']);
        $this->assertEquals(1, $data['success_count']);
        $this->assertEquals(1, $data['error_count']);
        $this->assertNotEmpty($data['errors']);
    }

    // ========================================================================
    // #65 — POST /api/teacher/assessments/{id}/release-results
    // ========================================================================

    /** @test */
    public function test_65_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->postJson('/api/teacher/assessments/1/release-results');

        $response->assertStatus(401);
    }

    /** @test */
    public function test_65_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->postJson('/api/teacher/assessments/1/release-results');

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_65_returns_404_when_non_owner_teacher()
    {
        // findOwnedByTeacher uses firstOrFail() which returns 404, not 403,
        // when the assessment exists but belongs to a different teacher.
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->postJson("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_65_returns_409_when_assessment_not_released()
    {
        $this->ensureAllUsers();

        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Draft',
            'type' => 'Recorded',
            'status' => 'draft']);
        AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertStatus(409);
        $this->assertEquals('ASSESSMENT_NOT_RELEASED', $response->json('error.code'));
    }

    /** @test */
    public function test_65_returns_409_when_submissions_still_pending_grading()
    {
        $this->ensureAllUsers();

        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released']);
        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'essay',
            'prompt' => 'Essay Q1',
            'max_points' => 10,
            'correct_answer' => null,
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'My essay response.']]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertStatus(409);
        $this->assertEquals('PENDING_GRADING_BLOCK', $response->json('error.code'));
    }

    /** @test */
    public function test_65_release_results_sets_is_results_released_flag()
    {
        $this->ensureAllUsers();
        $assessment = $this->createReleasedAssessment('Recorded', 1);
        $item = $assessment->items()->first();

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $item->id => 'A']]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertOk();
        // ARCH-005 block 4.5: ai_explanations_triggered semantics deleted (ARCH-002 FR-024) — the
        // decided release payload exposes only message/results_released_at.
        $response->assertJsonStructure(['data' => [
            'message',
            'results_released_at']]);

        $submission = AssessmentSubmission::where('assessment_id', $assessment->id)->first();
        $this->assertTrue((bool) $submission->is_results_released);
    }
}
