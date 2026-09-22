<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
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

#[Group('phase4-access')]
class Phase4AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    private ?User $otherStudent = null;

    private ?User $otherTeacher = null;

    
    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

    private ?Assessment $assessment = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = null;
        $this->student = null;
        $this->otherStudent = null;
        $this->otherTeacher = null;
        $this->section = null;
        $this->classroom = null;
        $this->competencyTag = null;
        $this->assessment = null;
    }

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
    }

    private function actAsStudent(): User
    {
        if (! $this->student) {
            $this->student = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->student);

        return $this->student;
    }

    private function actAsOtherStudent(): User
    {
        if (! $this->otherStudent) {
            $this->otherStudent = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->otherStudent);

        return $this->otherStudent;
    }

    private function actAsOtherTeacher(): User
    {
        if (! $this->otherTeacher) {
            $this->otherTeacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false,
            ]);
        }

        Sanctum::actingAs($this->otherTeacher);

        return $this->otherTeacher;
    }

    /**
     * Build a full org hierarchy: School Year -> Semester -> GradeLevel 7 -> Section
     * -> Subject -> Classroom (teacher scope derives from the classroom) + Student enrollment.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7',
        ]);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A',
        ]);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);

        $teacher = $this->actAsTeacher();

        $student = $this->actAsStudent();
        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now(),
        ]);

        // Enroll otherStudent for access-control tests (no teacher assignment needed).
        $this->actAsOtherStudent();
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id,
            'joined_at' => now(),
        ]);
    }

    /**
     * Create a released assessment with essay items and return the assessment model.
     */
    private function createReleasedAssessment(array $items): Assessment
    {
        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Phase 4 Grading Test',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $assessmentModel = Assessment::find($assessment['id']);

        foreach ($items as $itemData) {
            $this->actingAs($this->teacher, 'sanctum')
                ->post("/api/teacher/assessments/{$assessment['id']}/items", $itemData);
        }

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        return $assessmentModel->fresh();
    }

    /**
     * Helper: student starts and submits an assessment with essay items.
     * Returns the AssessmentSubmission (with attempt_id).
     */
    private function studentStartAndSubmit(int $assessmentId, array $responses): AssessmentSubmission
    {
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => $responses,
            ]);

        return AssessmentSubmission::where('assessment_id', $assessmentId)
            ->where('status', 'pending_grading')
            ->first();
    }

    /**
     * Helper: build a complete attempt for manual grading tests.
     * Returns the AssessmentAttempt (in pending_grading status).
     */
    private function setupPendingAttempt(int $itemCount = 2): AssessmentAttempt
    {
        $this->setUpOrg();

        $items = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $items[] = [
                'item_type' => 'essay',
                'prompt' => 'Question ' . $i,
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => $i,
            ];
        }

        $this->assessment = $this->createReleasedAssessment($items);

        $assessment = $this->assessment->fresh();
        $itemIds = $assessment->items()->orderBy('sort_order')->pluck('id')->all();

        $responses = [];
        foreach ($itemIds as $itemId) {
            $responses[(string) $itemId] = 'Student essay response for item ' . $itemId;
        }

        $submission = $this->studentStartAndSubmit($this->assessment->id, $responses);

        return AssessmentAttempt::findOrFail($submission->attempt_id);
    }

    // =========================================================================
    // #100 — POST /api/grades/manual (Teacher-only)
    // =========================================================================

    /**
     * Unauthenticated access to the manual grading endpoint must be rejected
     * at the auth:sanctum guard with 401 UNAUTHENTICATED.
     */
    public function test_100_unauthenticated_returns_401(): void
    {
        $response = $this->post('/api/grades/manual', [
            'attempt_id' => 1,
            'is_draft' => false,
            'grade_entries' => [
                [
                    'assessment_item_id' => 1,
                    'score' => 8,
                    'max_score' => 10,
                    'feedback' => 'Good answer.',
                ],
            ],
        ]);

        $response->assertStatus(401);
    }

    /**
     * An Admin user must be denied access to the teacher-only manual grading
     * endpoint with 403 FORBIDDEN (role middleware AuthorizationException).
     */
    public function test_100_admin_returns_403(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => 1,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => 1,
                        'score' => 8,
                        'max_score' => 10,
                        'feedback' => 'Good answer.',
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    /**
     * A Teacher whose account requires a password change must be blocked by
     * the password.change.required middleware with 403 PASSWORD_CHANGE_REQUIRED,
     * before the role check or controller logic runs.
     */
    public function test_100_must_change_password_returns_403(): void
    {
        $teacher = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => 1,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => 1,
                        'score' => 8,
                        'max_score' => 10,
                        'feedback' => 'Good answer.',
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    // =========================================================================
    // #101 — GET /api/mastery/records (Teacher/Student/Admin)
    // =========================================================================

    /**
     * A Student requesting mastery records for another student ID must receive
     * 403 FORBIDDEN with an explicit error code (ARCH-002 QA-004, ARCH-002 QA-004).
     */
    public function test_101_student_other_student_with_no_enrollment_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/mastery/records?student_id=' . $this->otherStudent->id);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // =========================================================================
    // #102 — GET /api/mastery/records/{studentId}/summary (Teacher/Student/Admin)
    // =========================================================================

    /**
     * Unauthenticated access to the mastery summary endpoint must be rejected
     * at the auth:sanctum guard with 401 UNAUTHENTICATED.
     */
    public function test_102_unauthenticated_returns_401(): void
    {
        $response = $this->get('/api/mastery/records/1/summary');

        $response->assertStatus(401);
    }

    /**
     * An Admin user must be able to view any student's mastery summary,
     * receiving 200 OK with the correct student_id in the response data.
     */
    public function test_102_admin_gets_student_mastery_summary(): void
    {
        $this->setUpOrg();
        $student = $this->actAsStudent();

        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/mastery/records/{$student->id}/summary");

        $response->assertOk();
        $response->assertJsonPath('data.student_id', $student->id);
    }

    // =========================================================================
    // #103 — POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit
    // =========================================================================

    /**
     * Unauthenticated access to the resubmit endpoint must be rejected at the
     * auth:sanctum guard with 401 UNAUTHENTICATED.
     */
    public function test_103_unauthenticated_returns_401(): void
    {
        $response = $this->post('/api/assessments/1/attempts/1/resubmit', [
            'reason' => 'Needs revision.',
        ]);

        $response->assertStatus(401);
    }

    /**
     * An Admin user must be denied access to the teacher-only resubmit endpoint
     * with 403 FORBIDDEN (role middleware AuthorizationException), even when a
     * valid scored attempt exists.
     */
    public function test_103_admin_returns_403(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Grade the attempt to 'scored' status.
        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $item->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'scored',
        ]);

        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Please revise.',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    /**
     * A Teacher whose account requires a password change must be blocked by
     * the password.change.required middleware with 403 PASSWORD_CHANGE_REQUIRED,
     * before the role check or controller logic runs.
     */
    public function test_103_must_change_password_returns_403(): void
    {
        $teacher = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->post('/api/assessments/1/attempts/1/resubmit', [
                'reason' => 'Needs revision.',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    // =========================================================================
    // #104 — POST /api/grades/bulk (Teacher-only)
    // =========================================================================

    /**
     * Unauthenticated access to the bulk grading endpoint must be rejected at
     * the auth:sanctum guard with 401 UNAUTHENTICATED.
     */
    public function test_104_unauthenticated_returns_401(): void
    {
        $response = $this->post('/api/grades/bulk', [
            'assessment_id' => 1,
            'grades' => [],
        ]);

        $response->assertStatus(401);
    }

    /**
     * An Admin user must be denied access to the teacher-only bulk grading
     * endpoint with 403 FORBIDDEN (role middleware AuthorizationException).
     */
    public function test_104_admin_returns_403(): void
    {
        $this->setupPendingAttempt(1);

        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => 1,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => 1,
                                'score' => 8,
                                'max_score' => 10,
                                'feedback' => 'Good.',
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    /**
     * A Teacher whose account requires a password change must be blocked by
     * the password.change.required middleware with 403 PASSWORD_CHANGE_REQUIRED,
     * before the role check or controller logic runs.
     */
    public function test_104_must_change_password_returns_403(): void
    {
        $teacher = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => 1,
                'grades' => [],
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }
}
