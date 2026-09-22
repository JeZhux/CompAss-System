<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentSubmission;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeEntry;
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

#[Group('phase4-grading-detail')]
class Phase4GradingDetailTest extends TestCase
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

    /**
     * Helper: make another enrolled student submit the current assessment.
     */
    private function otherStudentSubmit(int $assessmentId, array $responses): AssessmentSubmission
    {
        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => $responses,
            ]);

        return AssessmentSubmission::where('assessment_id', $assessmentId)
            ->where('student_id', $this->otherStudent->id)
            ->first();
    }

    // ========================================================================
    // #100 — POST /api/grades/manual (Teacher Manual Grading)
    // ========================================================================

    public function test_100_feedback_persisted_in_grade_entries(): void
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

        $entry0 = GradeEntry::where('assessment_submission_id', $attempt->submission->id)
            ->where('assessment_item_id', $items[0]->id)
            ->first();
        $this->assertNotNull($entry0->feedback);
        $this->assertSame('Good answer.', $entry0->feedback);

        $entry1 = GradeEntry::where('assessment_submission_id', $attempt->submission->id)
            ->where('assessment_item_id', $items[1]->id)
            ->first();
        $this->assertNotNull($entry1->feedback);
        $this->assertSame('Needs improvement.', $entry1->feedback);
    }

    public function test_100_graded_at_set_on_attempt_when_finalized(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

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
                    [
                        'assessment_item_id' => $items[1]->id,
                        'score' => 7,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $graded = AssessmentAttempt::find($attempt->id);
        $this->assertNotNull($graded->graded_at);
        $this->assertSame('scored', $graded->status);
    }

    public function test_100_draft_then_draft_overwrites_score(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        // First draft: score=5 for item 0.
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

        // Second draft: score=9 for item 0 (overwrites, not duplicated).
        $this->actingAs($this->teacher, 'sanctum')
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

        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 9.0,
            'is_draft' => true,
        ]);
        $this->assertSame(
            1,
            GradeEntry::where('assessment_submission_id', $attempt->submission->id)
                ->where('assessment_item_id', $items[0]->id)
                ->count()
        );
    }

    public function test_100_draft_then_finalize_writes_earned_points(): void
    {
        $attempt = $this->setupPendingAttempt(2);

        $assessment = $this->assessment->fresh();
        $items = $assessment->items()->orderBy('sort_order')->get();

        // 1. Save draft for item 0 (is_draft=true, score=5).
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

        // 2. Finalize: overwrite draft on item 0 (score=8) and score item 1.
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
                    [
                        'assessment_item_id' => $items[1]->id,
                        'score' => 7,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');

        // grade_entries.is_draft = false, score = 8.0 for item 0.
        $this->assertDatabaseHas('grade_entries', [
            'assessment_submission_id' => $attempt->submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 8.0,
            'is_draft' => false,
        ]);

        // assessment_responses.earned_points = 8.0 written on finalize.
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $attempt->submission->id,
            'item_id' => $items[0]->id,
            'earned_points' => 8.0,
            'is_auto_scored' => false,
        ]);

        // Attempt finalized to scored.
        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'scored',
        ]);
    }

    // ========================================================================
    // #103 — POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit
    // ========================================================================

    public function test_103_nonexistent_attempt_returns_404(): void
    {
        $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/99999/resubmit", [
                'reason' => 'Reason for resubmission.',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_103_resubmit_preserves_old_attempt_scored_status(): void
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

        $this->assertDatabaseHas('assessment_attempts', [
            'id' => $attempt->id,
            'status' => 'scored',
        ]);

        // Request resubmission.
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => 'Student needs to improve their answer.',
            ])
            ->assertOk();

        // Old attempt still scored (preserved for audit).
        $oldAttempt = AssessmentAttempt::find($attempt->id);
        $this->assertSame('scored', $oldAttempt->status);
    }

    // ========================================================================
    // #104 — POST /api/grades/bulk
    // ========================================================================

    public function test_104_bulk_grade_score_exceeds_max_collected_as_error(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Student 2 (otherStudent) submits.
        $this->otherStudentSubmit($this->assessment->id, [
            (string) $item->id => 'Answer 2.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 15,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                    [
                        'student_id' => $this->otherStudent->id,
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

        $response->assertOk();
        $response->assertJsonPath('data.success_count', 1);
        $response->assertJsonPath('data.error_count', 1);

        $errors = $response->json('data.errors');
        $this->assertCount(1, $errors);
        $this->assertEquals($this->student->id, $errors[0]['student_id']);
    }

    public function test_104_bulk_grade_already_scored_collected_as_error(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Student 2 submits.
        $this->otherStudentSubmit($this->assessment->id, [
            (string) $item->id => 'Answer 2.',
        ]);

        // Grade student 1 to scored via #100 endpoint.
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

        // Bulk grade both: student 1 already scored (error), student 2 (success).
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 9,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                    [
                        'student_id' => $this->otherStudent->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $item->id,
                                'score' => 7,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.success_count', 1);
        $response->assertJsonPath('data.error_count', 1);

        $errors = $response->json('data.errors');
        $this->assertCount(1, $errors);
        $this->assertEquals($this->student->id, $errors[0]['student_id']);
    }

    public function test_104_bulk_grade_graded_at_set_on_success(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $assessment = $this->assessment->fresh();
        $item = $assessment->items()->orderBy('sort_order')->first();

        // Student 2 submits.
        $this->otherStudentSubmit($this->assessment->id, [
            (string) $item->id => 'Answer 2.',
        ]);

        $this->actingAs($this->teacher, 'sanctum')
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
            ])
            ->assertOk();

        // Successfully graded attempt has graded_at set.
        $graded = AssessmentAttempt::find($attempt->id);
        $this->assertNotNull($graded->graded_at);
        $this->assertSame('scored', $graded->status);
    }

    // ========================================================================
    // #101 — GET /api/mastery/records
    // ========================================================================

    public function test_101_competency_id_filter_accepted(): void
    {
        $this->setUpOrg();
        $this->actAsTeacher();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/mastery/records?competency_id=1');

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function test_101_subject_id_filter_accepted(): void
    {
        $this->setUpOrg();
        $this->actAsTeacher();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/mastery/records?subject_id=1');

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }
}
