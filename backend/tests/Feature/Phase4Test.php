<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\AssessmentResponse;
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
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('phase4')]
class Phase4Test extends TestCase
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
                'prompt' => 'Question '.$i,
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
            $responses[(string) $itemId] = 'Student essay response for item '.$itemId;
        }

        $submission = $this->studentStartAndSubmit($this->assessment->id, $responses);

        return AssessmentAttempt::findOrFail($submission->attempt_id);
    }

    // ========================================================================
    // #100 — POST /api/grades/manual (Teacher Manual Grading)
    // ========================================================================

    public function test_100_teacher_grades_subjective_items_moves_to_scored(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                        'feedback' => 'Good answer.',
                    ],
                    [
                        'assessment_item_id' => $items[1]->id,
                        'score' => 7,
                        'max_score' => 10,
                        'feedback' => 'Needs improvement.',
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');
        $response->assertJsonPath('data.grader_id', $this->teacher->id);
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'scored',
            'grader_id' => $this->teacher->id,
        ]);
        $this->assertDatabaseHas('assessment_submissions', [
            'attempt_id' => $attempt->id,
            'status' => 'scored',
        ]);
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 8.0,
            'is_draft' => false,
        ]);
    }

    public function test_100_teacher_saves_draft_stays_pending_grading(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => true,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                        'feedback' => 'Draft feedback.',
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'pending_grading',
            'grader_id' => null,
        ]);
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 8.0,
            'is_draft' => true,
        ]);
        // earned_points should NOT be written for draft grades.
        $this->assertDatabaseMissing('assessment_responses', [
            'submission_id' => $attempt->submission->id,
            'item_id' => $items[0]->id,
            'earned_points' => 8.0,
        ]);
    }

    public function test_100_teacher_grades_partial_stays_pending_grading(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'pending_grading',
        ]);
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 8.0,
            'is_draft' => false,
        ]);
        // Second item should not be graded.
        $this->assertDatabaseMissing('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[1]->id,
        ]);
    }

    public function test_100_teacher_grades_non_pending_attempt_returns_409_not_pending_grading(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        // First: fully grade the attempt → transitions to 'scored'.
        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                    [
                        'assessment_item_id' => $items[1]->id,
                        'score' => 7,
                        'max_score' => 10,
                    ],
                ],
            ]);

        // Second: attempt to grade again → 409 NOT_PENDING_GRADING.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 9,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_PENDING_GRADING');
    }

    public function test_100_teacher_draft_on_scored_attempt_returns_409(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // First: grade to 'scored'.
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

        // Second: try to save a draft on the now-scored attempt → 409.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => true,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $item->id,
                        'score' => 5,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_PENDING_GRADING');
    }

    public function test_100_teacher_scores_above_max_returns_422_validation_error(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $item->id,
                        'score' => 15,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_100_non_owner_teacher_returns_403(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        $this->actAsOtherTeacher();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
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

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_100_student_cannot_access_grades_endpoint_returns_403(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        $response = $this->actingAs($this->student, 'sanctum')
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

        $response->assertStatus(403);
    }

    public function test_100_nonexistent_attempt_returns_422_validation_error(): void
    {
        $this->setUpOrg();
        $this->actAsTeacher();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => 99999,
                'grade_entries' => [
                    [
                        'assessment_item_id' => 1,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ========================================================================
    // #101 — GET /api/mastery/records
    // =========================================================================

    public function test_101_teacher_gets_mastery_records_returns_empty_stub(): void
    {
        $this->setUpOrg();
        $student = $this->actAsStudent();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/mastery/records?student_id='.$student->id);

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function test_101_student_gets_own_mastery_records(): void
    {
        $this->setUpOrg();
        $student = $this->actAsStudent();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/mastery/records');

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function test_101_student_gets_other_student_mastery_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/mastery/records?student_id='.$this->otherStudent->id);

        $response->assertStatus(403);
    }

    public function test_101_admin_gets_mastery_records(): void
    {
        $this->setUpOrg();
        $admin = User::factory()->create([
            'role' => 'Admin',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/mastery/records?student_id='.$this->student->id);

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function test_101_unauthenticated_returns_401(): void
    {
        $response = $this->get('/api/mastery/records');

        $response->assertStatus(401);
    }

    // ========================================================================
    // #102 — GET /api/mastery/records/{studentId}/summary
    // ========================================================================

    public function test_102_teacher_gets_student_mastery_summary(): void
    {
        $this->setUpOrg();
        $student = $this->actAsStudent();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/mastery/records/{$student->id}/summary");

        $response->assertOk();
        $response->assertJsonPath('data.student_id', $student->id);
        $response->assertJsonPath('data.competencies', []);
        $response->assertJsonPath('data.overall_level', 'Not Yet Computed');
        $response->assertJsonPath('data.last_updated', null);
    }

    public function test_102_student_gets_own_mastery_summary(): void
    {
        $this->setUpOrg();
        $student = $this->actAsStudent();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/mastery/records/{$student->id}/summary");

        $response->assertOk();
        $response->assertJsonPath('data.student_id', $student->id);
    }

    public function test_102_student_gets_other_student_summary_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/mastery/records/{$this->otherStudent->id}/summary");

        $response->assertStatus(403);
    }

    public function test_102_nonexistent_student_returns_404(): void
    {
        $this->setUpOrg();
        $this->actAsTeacher();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/mastery/records/99999/summary');

        $response->assertStatus(404);
    }

    // ========================================================================
    // #103 — POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit
    // =========================================================================

    public function test_103_teacher_resubmits_scored_attempt_creates_new_attempt(): void
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

        // Request resubmission.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Student needs to improve their answer.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.old_attempt_id', $attempt->id);
        $response->assertJsonPath('data.reason', 'Student needs to improve their answer.');
        $newAttemptId = $response->json('data.new_attempt_id');

        // Old attempt remains scored.
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'scored',
        ]);

        // New attempt is in_progress with incremented attempt_number.
        $newAttempt = AssessmentAttempt::findOrFail($newAttemptId);
        $this->assertEquals('in_progress', $newAttempt->status);
        $this->assertEquals(2, $newAttempt->attempt_number);
        $this->assertEquals($attempt->assessment_id, $newAttempt->assessment_id);
        $this->assertEquals($attempt->student_id, $newAttempt->student_id);
    }

    public function test_resubmission_request_records_resubmission_state(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Grade the attempt to scored.
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

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Revise your answer.',
            ]);

        $response->assertOk();
        $newAttemptId = $response->json('data.new_attempt_id');

        // ARCH-002 FR-018: the resubmission attempt carries the full resubmission state.
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $newAttemptId,
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $attempt->id,
            'resubmission_reason' => 'Revise your answer.',
        ]);

        $newAttempt = AssessmentAttempt::findOrFail($newAttemptId);
        $this->assertTrue($newAttempt->is_resubmission);
        $this->assertEquals($attempt->id, $newAttempt->resubmission_of_attempt_id);
        $this->assertEquals('Revise your answer.', $newAttempt->resubmission_reason);
        $this->assertNotNull($newAttempt->resubmission_requested_at);

        // ARCH-004 §4.1: started_at stays null until the student starts the attempt.
        $this->assertNull($newAttempt->started_at);
        $this->assertEquals('in_progress', $newAttempt->status);
    }

    public function test_103_teacher_resubmits_pending_attempt_returns_409_not_scored(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Please revise.',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_SCORED');
    }

    public function test_103_teacher_resubmits_when_student_has_in_progress_returns_409(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Grade the attempt to scored.
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

        // Manually create an in_progress attempt to simulate the conflict.
        AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 99,
            'status' => 'in_progress',
            'response_history' => [],
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Revise your answer.',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'STUDENT_HAS_ACTIVE_ATTEMPT');
    }

    public function test_103_non_owner_teacher_returns_403(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Grade to scored.
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

        $this->actAsOtherTeacher();

        $response = $this->actingAs($this->otherTeacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Revise.',
            ]);

        $response->assertStatus(403);
    }

    public function test_103_student_cannot_resubmit_returns_403(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Revise.',
            ]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // #104 — POST /api/grades/bulk
    // =========================================================================

    public function test_104_teacher_bulk_grades_multiple_students(): void
    {
        $this->setUpOrg();

        $items = [
            [
                'item_type' => 'essay',
                'prompt' => 'Question 1.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ];

        $this->assessment = $this->createReleasedAssessment($items);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Student 1 submits.
        $submission1 = $this->studentStartAndSubmit($this->assessment->id, [
            (string) $item->id => 'Answer 1.',
        ]);

        // Student 2 (otherStudent) submits.
        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$this->assessment->id}/start");
        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$this->assessment->id}/submit", [
                'responses' => [(string) $item->id => 'Answer 2.'],
            ]);
        $submission2 = AssessmentSubmission::where('assessment_id', $this->assessment->id)
            ->where('student_id', $this->otherStudent->id)
            ->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 8,
                                'max_score' => 10,
                                'feedback' => 'Good.',
                            ],
                        ],
                    ],
                    [
                        'student_id' => $this->otherStudent->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 9,
                                'max_score' => 10,
                                'feedback' => 'Great.',
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_graded', 2);
        $response->assertJsonPath('data.success_count', 2);
        $response->assertJsonPath('data.error_count', 0);

        $this->assertDatabaseHas('assessment_attempts', [
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'status' => 'scored',
        ]);
        $this->assertDatabaseHas('assessment_attempts', [
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->otherStudent->id,
            'status' => 'scored',
        ]);
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $submission1->id,
            'assessment_item_id' => $item->id,
            'score' => 8.0,
            'is_draft' => false,
        ]);
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $submission2->id,
            'assessment_item_id' => $item->id,
            'score' => 9.0,
            'is_draft' => false,
        ]);
    }

    public function test_104_teacher_bulk_grade_partial_success_collects_errors(): void
    {
        $this->setUpOrg();

        $items = [
            [
                'item_type' => 'essay',
                'prompt' => 'Question 1.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ];

        $this->assessment = $this->createReleasedAssessment($items);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Student has a pending_grading attempt.
        $submission1 = $this->studentStartAndSubmit($this->assessment->id, [
            (string) $item->id => 'Answer 1.',
        ]);

        // otherStudent is enrolled but has NOT submitted (no pending_grading attempt).
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 8,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                    [
                        'student_id' => $this->otherStudent->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 9,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_graded', 2);
        $response->assertJsonPath('data.success_count', 1);
        $response->assertJsonPath('data.error_count', 1);

        $errors = $response->json('data.errors');
        $this->assertCount(1, $errors);
        $this->assertEquals($this->otherStudent->id, $errors[0]['student_id']);

        // Student was graded successfully.
        $this->assertDatabaseHas('assessment_attempts', [
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'status' => 'scored',
        ]);
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $submission1->id,
            'assessment_item_id' => $item->id,
            'score' => 8.0,
        ]);
    }

    public function test_104_teacher_does_not_own_assessment_returns_403(): void
    {
        $this->setUpOrg();
        $this->actAsOtherTeacher();

        $items = [
            [
                'item_type' => 'essay',
                'prompt' => 'Question 1.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ];

        // Create a second subject + assessment owned by otherTeacher.
        $otherSubject = Subject::create(['grade_level_id' => $this->section->grade_level_id, 'name' => 'Science', 'code' => 'SCI7']);
        $otherClassroom = app(ClassroomService::class)->createClassroom($this->otherTeacher->id, $otherSubject->id, $this->section->id, '2026-2027', null);

        $otherAssessment = $this->actingAs($this->otherTeacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$otherClassroom->id.'/assessments', [
                'title' => 'Other Assessment',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $item = AssessmentItem::create([
            'assessment_id' => $otherAssessment['id'],
            'item_type' => 'essay',
            'prompt' => 'Question.',
            'max_points' => 10,
            'correct_answer' => null,
            'competency_tag_id' => $this->competencyTag->id,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $otherAssessment['id'],
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 8,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_104_student_cannot_access_bulk_endpoint_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => 1,
                'grades' => [],
            ]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // #100+#103 — End-to-end: grade → resubmit → grade again
    // ========================================================================

    /**
     * ARCH-002 FR-018 E2E: manual grade → resubmission request → student start
     * (admitted) → student submit → teacher regrades the resubmission.
     * Asserts the resubmission state columns, attempt state transitions, and
     * that grading the new attempt records mastery without UNIQUE collisions
     * (R-01: corrections land on a NEW attempt row, so the UNIQUE keys on
     * assessment_responses / grade_entries / mastery_records are not violated).
     * Known-red retired: GREEN since ARCH-002 FR-018 (Phase 3, WU-3; R-10) — the
     * attribute was deleted in the open-questions resolution pass (OQ-3.4).
     */
    public function test_e2e_manual_grade_then_resubmit_then_regrade(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // 1. Teacher grades the attempt → scored.
        $gradeResponse = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $item->id,
                        'score' => 5,
                        'max_score' => 10,
                        'feedback' => 'Needs work.',
                    ],
                ],
            ]);

        $gradeResponse->assertOk();
        $gradeResponse->assertJsonPath('data.status', 'scored');

        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $item->id,
            'score' => 5.0,
            'is_draft' => false,
        ]);

        // 2. Teacher requests resubmission → new in_progress attempt that
        // carries the full ARCH-002 FR-018 resubmission state (student has not started).
        $resubmitResponse = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Revise your answer.',
            ]);

        $resubmitResponse->assertOk();
        $newAttemptId = $resubmitResponse->json('data.new_attempt_id');

        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $newAttemptId,
            'status' => 'in_progress',
            'attempt_number' => 2,
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $attempt->id,
            'resubmission_reason' => 'Revise your answer.',
        ]);

        $newAttempt = AssessmentAttempt::findOrFail($newAttemptId);
        $this->assertNotNull($newAttempt->resubmission_requested_at);
        $this->assertNull($newAttempt->started_at);

        // 3. Student starts the resubmission attempt and submits.
        // ARCH-002 FR-018: /start admits the teacher-created resubmission attempt.
        $startResponse = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$this->assessment->id}/start");
        $startResponse->assertOk();
        $startResponse->assertJsonPath('data.attempt_id', $newAttemptId);
        $startResponse->assertJsonPath('data.attempt_number', 2);

        // ARCH-004 §4.1: the start stamps started_at on the resubmission attempt.
        $this->assertNotNull($newAttempt->fresh()->started_at);

        $submitResponse = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$this->assessment->id}/submit", [
                'responses' => [(string) $item->id => 'Revised essay answer.'],
            ]);

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('data.status', 'pending_grading');

        $newAttempt = AssessmentAttempt::findOrFail($newAttemptId);
        $this->assertEquals('pending_grading', $newAttempt->status);

        $this->assertDatabaseHas('assessment_submissions', [
            'attempt_id' => $newAttemptId,
            'status' => 'pending_grading',
        ]);

        // 4. Teacher grades the new attempt.
        $regradeResponse = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $newAttemptId,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $item->id,
                        'score' => 9,
                        'max_score' => 10,
                        'feedback' => 'Much better.',
                    ],
                ],
            ]);

        $regradeResponse->assertOk();
        $regradeResponse->assertJsonPath('data.status', 'scored');

        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $newAttempt->submission->id,
            'assessment_item_id' => $item->id,
            'score' => 9.0,
        ]);

        // 5. Old attempt still scored; new attempt scored — two attempts,
        // two submissions, no UNIQUE collision (R-01).
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'scored',
        ]);
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $newAttemptId,
            'status' => 'scored',
        ]);

        $this->assertSame(2, AssessmentResponse::query()
            ->where('item_id', $item->id)
            ->count());
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $attempt->submission->id,
            'item_id' => $item->id,
            'earned_points' => 5.0,
            'is_auto_scored' => false,
        ]);
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $newAttempt->submission->id,
            'item_id' => $item->id,
            'earned_points' => 9.0,
            'is_auto_scored' => false,
        ]);

        // 6. Mastery recorded per submission (append-only, ARCH-002 FR-020): the
        // resubmission's record is a NEW row keyed by its own submission —
        // the UNIQUE (student, competency, assessment, submission) is not
        // violated by regrading (R-01).
        $this->assertSame(2, MasteryRecord::query()
            ->where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag->id)
            ->where('assessment_id', $this->assessment->id)
            ->count());

        $oldMastery = MasteryRecord::query()
            ->where('assessment_submission_id', $attempt->submission->id)
            ->firstOrFail();
        $this->assertEquals(50.0, (float) $oldMastery->mastery_percent);
        $this->assertEquals('Not_Mastered', $oldMastery->mastery_status);

        $newMastery = MasteryRecord::query()
            ->where('assessment_submission_id', $newAttempt->submission->id)
            ->firstOrFail();
        $this->assertEquals(90.0, (float) $newMastery->mastery_percent);
        $this->assertEquals('Mastered', $newMastery->mastery_status);
    }

    // ========================================================================
    // #100 — Draft save then finalize
    // ========================================================================

    public function test_100_draft_then_finalize_grades(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        // 1. Save first item as draft.
        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => true,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 5,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 5.0,
            'is_draft' => true,
        ]);

        // Attempt still pending_grading.
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'pending_grading',
        ]);

        // 2. Finalize with both items (overwrites draft for item 0, adds item 1).
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                    [
                        'assessment_item_id' => $items[1]->id,
                        'score' => 7,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');

        // Grade entries should now be non-draft.
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 8.0,
            'is_draft' => false,
        ]);
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[1]->id,
            'score' => 7.0,
            'is_draft' => false,
        ]);

        // Attempt transitioned to scored.
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'scored',
        ]);

        // earned_points should now be written to assessment_responses.
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $attempt->submission->id,
            'item_id' => $items[0]->id,
            'earned_points' => 8.0,
            'is_auto_scored' => false,
        ]);
    }

    // ========================================================================
    // ARCH-002 FR-018 — Foreign-item grade guards (#100 manual + draft paths)
    // ========================================================================

    public function test_100_grade_item_not_in_assessment_returns_409_and_writes_no_grade_entries(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        // Second assessment (same teacher) whose essay item is foreign to the
        // attempt's assessment.
        $foreignAssessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Foreign question.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);
        $foreignItem = $foreignAssessment->items()->orderBy('sort_order')->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $foreignItem->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ITEM_NOT_IN_ASSESSMENT');
        $this->assertDatabaseCount('grade_entries', 0);
    }

    public function test_100_grade_objective_item_returns_409_not_a_subjective_item_and_writes_no_grade_entries(): void
    {
        $this->setUpOrg();

        // Mixed assessment: objective item auto-scored at submit; the essay
        // keeps the attempt in pending_grading.
        $this->assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
            ],
        ]);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        $submission = $this->studentStartAndSubmit($this->assessment->id, [
            (string) $items[0]->id => 'B',
            (string) $items[1]->id => 'Essay response.',
        ]);

        // The manual-grade path is essay-only (REQ-17: "essay-only wrapper"):
        // scoring an objective item through it must be rejected.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $submission->attempt_id,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_A_SUBJECTIVE_ITEM');
        $this->assertDatabaseCount('grade_entries', 0);
    }

    public function test_100_regrade_same_item_in_pending_grading_returns_409_already_scored(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        // First: partial finalize of item 0 only — attempt stays pending_grading.
        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $attempt->submission->id,
            'item_id' => $items[0]->id,
            'earned_points' => 8.0,
        ]);

        // Second: grade the same response again via the non-regrade path —
        // already scored → rejected, no new ledger row.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 9,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'RESPONSE_ALREADY_SCORED');
        $this->assertDatabaseCount('grade_entries', 1);
    }

    public function test_100_draft_foreign_item_returns_409_and_writes_no_grade_entries(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $foreignAssessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Foreign question.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);
        $foreignItem = $foreignAssessment->items()->orderBy('sort_order')->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => true,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $foreignItem->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ITEM_NOT_IN_ASSESSMENT');
        $this->assertDatabaseCount('grade_entries', 0);
    }

    public function test_100_draft_objective_item_returns_409_and_writes_no_grade_entries(): void
    {
        $this->setUpOrg();

        $this->assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'true_false',
                'prompt' => '1+1=2?',
                'max_points' => 10,
                'correct_answer' => 'true',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
            ],
        ]);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        $submission = $this->studentStartAndSubmit($this->assessment->id, [
            (string) $items[0]->id => 'true',
            (string) $items[1]->id => 'Essay response.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $submission->attempt_id,
                'is_draft' => true,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_A_SUBJECTIVE_ITEM');
        $this->assertDatabaseCount('grade_entries', 0);
    }

    public function test_100_draft_already_scored_response_returns_409(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        // Partial finalize of item 0 — attempt stays pending_grading.
        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => false,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        // Drafting an already-scored response is a double-write → rejected.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => true,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $items[0]->id,
                        'score' => 9,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'RESPONSE_ALREADY_SCORED');
        $this->assertDatabaseCount('grade_entries', 1);
    }
}
