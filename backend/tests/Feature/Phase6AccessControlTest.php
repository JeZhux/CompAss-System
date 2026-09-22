<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\AssessmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 *
 * Access-control + error-envelope + data-shape matrix for Phase 6
 * (Analytics Dashboard, API Interface Spec §3.8, #77–#82).
 *
 * Routes (verified from routes/api.php):
 *   #77  GET /api/teacher/dashboard/heatmap?subject_id=&assessment_id= — Teacher
 *   #78  GET /api/teacher/dashboard/gap-report?subject_id=&group_by= — Teacher
 *   #79  GET /api/teacher/dashboard/trends?subject_id=&competency_code= — Teacher
 *   #80  GET /api/teacher/dashboard/student-drill-down?student_id=&subject_id= — Teacher
 *   #81  GET /api/admin/dashboard/school-wide-overview — Admin
 *   #82  GET /api/student/dashboard/mastery-history?competency_id= — Student (self)
 *
 * Middleware stack: auth:sanctum -> password.change.required -> role
 * Error codes (bootstrap/app.php): 400 BAD_REQUEST, 401 UNAUTHENTICATED,
 * 403 FORBIDDEN / PASSWORD_CHANGE_REQUIRED, 404 NOT_FOUND, 422 VALIDATION_ERROR.
 *
 * All dashboard data is read exclusively from mastery_records, which only
 * Recorded Assessments populate — Unrecorded submissions must not appear here
 * (ARCH-002 FR-021, ARCH-002 FR-021, ARCH-002 FR-022).
 */
#[Group('phase6-access')]
class Phase6AccessControlTest extends TestCase
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

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P6']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P6']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P6']);
        
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
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id]);
    }

    /**
     * Create and submit a released assessment. Mastery flows from real
     * auto-scoring + CompetencyMappingService.computeMastery (Phase 5 wired),
     * so mastery_records are populated — but only for Recorded Assessments.
     *
     * @param  int     $itemCount          number of multiple-choice items (all tagged competencyTag1)
     * @param  array   $correctPositions   1-based item positions answered correctly ('A'); others get 'B'
     * @param  string  $type               Recorded | Unrecorded
     */
    private function createScoredSubmission(int $itemCount, array $correctPositions, string $type = 'Recorded'): AssessmentSubmission
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'P6 ' . $type,
            'type' => $type,
            'status' => 'released']);

        $items = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $items[] = AssessmentItem::create([
                'assessment_id' => $assessment->id,
                'item_type' => 'multiple_choice',
                'prompt' => "Q{$i}",
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id,
                'sort_order' => $i]);
        }

        $responses = [];
        foreach ($items as $position => $item) {
            $idx = $position + 1; // 1-based
            $responses[(string) $item->id] = in_array($idx, $correctPositions, true) ? 'A' : 'B';
        }

        Sanctum::actingAs($this->student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => $responses]);

        return AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('status', 'scored')
            ->first();
    }

    /** A Recorded submission scoring 1/3 (33%) -> Not_Mastered -> NotCompetentFlag. */
    private function createNotMasteredSubmission(): void
    {
        $this->createScoredSubmission(3, [1], 'Recorded');
    }

    // ====================================================================
    // #77 — GET /api/teacher/dashboard/heatmap
    // ====================================================================

    /** @test */
    public function test_77_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_77_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_77_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_77_returns_403_when_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_77_returns_400_when_subject_id_missing()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap');

        $response->assertStatus(400);
        $this->assertEquals('BAD_REQUEST', $response->json('error.code'));
    }

    /** @test */
    public function test_77_returns_403_when_teacher_not_assigned()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }

    /** @test */
    public function test_77_returns_404_when_nonexistent_subject()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=999999');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_77_returns_empty_when_no_mastery_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
        $this->assertCount(0, $response->json('data.competencies'));
    }

    /** @test */
    public function test_77_returns_mastery_rates_when_records_exist()
    {
        $this->ensureAllUsers();
        $submission = $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.competencies')));
        $competency = $response->json('data.competencies.0');
        $this->assertEquals('M7-ALG-01', $competency['code']);
        $this->assertEquals(100.0, $competency['mastery_rate_percent']);
        $this->assertEquals(1, $competency['mastered_count']);
    }

    /** @test */
    public function test_77_supports_assessment_id_filter()
    {
        $this->ensureAllUsers();
        $submission = $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id . '&assessment_id=' . $submission->assessment_id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.competencies')));
        $this->assertEquals($submission->assessment_id, $response->json('data.assessment_id'));
    }

    /** @test */
    public function test_77_excludes_unrecorded_submissions()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1], 'Recorded');
        $this->createScoredSubmission(1, [1], 'Unrecorded');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/heatmap?subject_id=' . $this->subject->id);

        $response->assertOk();
        // Only one mastery record exists (Unrecorded produces none), and the
        // heatmap surfaces only that single Recorded competency.
        $this->assertEquals(1, MasteryRecord::query()->count());
        $this->assertCount(1, $response->json('data.competencies'));
    }

    // ====================================================================
    // #78 — GET /api/teacher/dashboard/gap-report
    // ====================================================================

    /** @test */
    public function test_78_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id);

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_78_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_78_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_78_returns_403_when_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_78_returns_400_when_subject_id_missing()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report');

        $response->assertStatus(400);
        $this->assertEquals('BAD_REQUEST', $response->json('error.code'));
    }

    /** @test */
    public function test_78_returns_422_when_group_by_invalid()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id . '&group_by=nope');

        $response->assertStatus(422);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('group_by', $response->json('error.fields'));
    }

    /** @test */
    public function test_78_returns_empty_gaps_when_all_mastered()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.gaps'));
        $this->assertEquals('section', $response->json('data.group_by'));
    }

    /** @test */
    public function test_78_lists_not_mastered_competencies_when_student_grouped()
    {
        $this->ensureAllUsers();
        $this->createNotMasteredSubmission();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id . '&group_by=student');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.gaps')));
        $gap = $response->json('data.gaps.0');
        $this->assertEquals('M7-ALG-01', $gap['competency_code']);
        $this->assertEquals('Not_Mastered', $gap['mastery_status']);
    }

    /** @test */
    public function test_78_lists_gaps_at_section_level_when_not_mastered()
    {
        $this->ensureAllUsers();
        $this->createNotMasteredSubmission();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/gap-report?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.gaps')));
        $this->assertGreaterThan(0, $response->json('data.gaps.0.not_mastered_count'));
    }

    // ====================================================================
    // #79 — GET /api/teacher/dashboard/trends
    // ====================================================================

    /** @test */
    public function test_79_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->subject->id);

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_79_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_79_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_79_returns_404_when_nonexistent_subject()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=999999');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_79_returns_empty_when_no_mastery_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.trends'));
    }

    /** @test */
    public function test_79_returns_trend_points_when_records_exist()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.trends')));
        $trend = $response->json('data.trends.0');
        $this->assertArrayHasKey('assessment_id', $trend);
        $this->assertArrayHasKey('mastery_rate_percent', $trend);
        $this->assertEquals(100.0, $trend['mastery_rate_percent']);
    }

    /** @test */
    public function test_79_supports_competency_code_filter()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->subject->id . '&competency_code=M7-ALG-01');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.trends')));
        $this->assertEquals('M7-ALG-01', $response->json('data.competency_code'));
    }

    /** @test */
    public function test_79_returns_empty_when_competency_code_does_not_match()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/trends?subject_id=' . $this->subject->id . '&competency_code=NOPE-00');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.trends'));
    }

    // ====================================================================
    // #80 — GET /api/teacher/dashboard/student-drill-down
    // ====================================================================

    /** @test */
    public function test_80_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id . '&subject_id=' . $this->subject->id);

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_80_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id . '&subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_80_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id . '&subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_80_returns_403_when_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id . '&subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_80_returns_403_when_teacher_not_assigned()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id . '&subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('SUBJECT_NOT_ASSIGNED', $response->json('error.code'));
    }

    /** @test */
    public function test_80_returns_404_when_nonexistent_subject()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id . '&subject_id=999999');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_80_returns_404_when_nonexistent_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=999999&subject_id=' . $this->subject->id);

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_80_returns_403_when_student_not_enrolled_in_section()
    {
        $this->ensureAllUsers();
        $unenrolledStudent = User::factory()->create([
            'role' => 'Student']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $unenrolledStudent->id . '&subject_id=' . $this->subject->id);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_80_teacher_can_view_enrolled_student_drill_down_with_history()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/dashboard/student-drill-down?student_id=' . $this->student->id . '&subject_id=' . $this->subject->id);

        $response->assertOk();
        $competency = $response->json('data.competencies.0');
        $this->assertEquals('M7-ALG-01', $competency['code']);
        $this->assertGreaterThanOrEqual(1, count($competency['history']));
        $this->assertEquals('Mastered', $competency['current_mastery_status']);
    }

    // ====================================================================
    // #81 — GET /api/admin/dashboard/school-wide-overview
    // ====================================================================

    /** @test */
    public function test_81_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_81_returns_403_when_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_81_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->mustChangeAdmin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_81_returns_403_when_student()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_81_returns_empty_when_no_mastery_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data['grade_levels']);
        $this->assertEquals(0.0, $data['overall_mastery_rate_percent']);
        $this->assertIsArray($data['by_competency']);
    }

    /** @test */
    public function test_81_returns_overview_when_records_exist()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/dashboard/school-wide-overview');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($data['by_competency']));
        $this->assertNotEmpty($data['by_competency'][0]['code']);
        $this->assertGreaterThan(0, $data['overall_mastery_rate_percent']);
        $this->assertNotEmpty($data['grade_levels']);
        $this->assertEquals('7', $data['grade_levels'][0]['grade_level']);
    }

    // ====================================================================
    // #82 — GET /api/student/dashboard/mastery-history
    // ====================================================================

    /** @test */
    public function test_82_returns_401_when_unauthenticated()
    {
        $this->ensureAllUsers();

        $response = $this->getJson('/api/student/dashboard/mastery-history');

        $response->assertStatus(401);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_82_returns_403_when_teacher()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_82_returns_403_when_admin()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_82_returns_403_when_must_change_password()
    {
        $this->ensureAllUsers();

        $mustChangeStudent = User::factory()->create([
            'role' => 'Student', 'must_change_password' => true]);

        $response = $this->actingAs($mustChangeStudent, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertStatus(403);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_82_student_self_scoped_returns_own_history()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $data = $response->json('data');
        // Self-scoped: the student always sees their own data (no student_id param).
        $this->assertEquals($this->student->id, $data['student_id']);
        $this->assertGreaterThanOrEqual(1, count($data['competencies']));
        $competency = $response->json('data.competencies.0');
        $this->assertEquals('M7-ALG-01', $competency['code']);
        $this->assertEquals('Mastered', $competency['current_mastery_status']);
        $this->assertGreaterThanOrEqual(1, count($competency['history']));
    }

    /** @test */
    public function test_82_returns_empty_when_no_mastery_records()
    {
        $this->ensureAllUsers();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.competencies'));
    }

    /** @test */
    public function test_82_supports_competency_id_filter()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $competencyId = $this->competencyTag1->id;

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history?competency_id=' . $competencyId);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.competencies')));
        $this->assertEquals($competencyId, $response->json('data.competencies.0.competency_id'));
    }

    /** @test */
    public function test_82_excludes_unrecorded_from_history()
    {
        $this->ensureAllUsers();
        // Only an Unrecorded submission -> no mastery records -> empty history.
        $this->createScoredSubmission(1, [1], 'Unrecorded');

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/dashboard/mastery-history');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.competencies'));
    }

    // ====================================================================
    // Service-level coverage for the unrouted getAggregateCompetencySummary
    // (ARCH-001 §5.1 deliverable method).
    // ====================================================================

    /** @test */
    public function test_service_aggregate_competency_summary_returns_data()
    {
        $this->ensureAllUsers();
        $this->createScoredSubmission(1, [1]);

        $summary = app(AnalyticsService::class)->getAggregateCompetencySummary([$this->section->id]);

        $this->assertGreaterThanOrEqual(1, count($summary));
        $this->assertEquals('M7-ALG-01', $summary[0]['code']);
        $this->assertEquals(1, $summary[0]['mastered_count']);
        $this->assertEquals(100.0, $summary[0]['mastery_rate_percent']);
    }

    /** @test */
    public function test_service_aggregate_competency_summary_empty_when_no_data()
    {
        $this->ensureAllUsers();

        $summary = app(AnalyticsService::class)->getAggregateCompetencySummary([$this->section->id]);

        $this->assertCount(0, $summary);
    }
}
