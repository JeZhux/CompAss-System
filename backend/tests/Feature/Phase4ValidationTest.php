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

#[Group('phase4-validation')]
class Phase4ValidationTest extends TestCase
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

        $this->actAsOtherStudent();
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id,
            'joined_at' => now(),
        ]);
    }

    private function assertValidationError($response, ?string $field = null): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['error' => ['message', 'code', 'fields']]);
        if ($field !== null) {
            $fields = $response->json('error.fields');
            $this->assertArrayHasKey($field, $fields);
        }
    }

    /**
     * Create a released assessment with essay items and return the Assessment model.
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
    private function setupPendingAttempt(int $itemCount = 1): AssessmentAttempt
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

    private function firstItemId(int $assessmentId): int
    {
        return (int) AssessmentItem::where('assessment_id', $assessmentId)->value('id');
    }

    /**
     * Set up org + a released assessment with one essay item.
     * Returns the first (only) assessment item ID.
     */
    private function setupSingleItemAssessment(): int
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

        return $this->firstItemId($this->assessment->id);
    }

    // ========================================================================
    // StoreManualGradeRequest validation — POST /api/grades/manual (#100)
    // ========================================================================

    public function test_100_validation_missing_attempt_id(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'attempt_id');
    }

    public function test_100_validation_invalid_attempt_id(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => 999999,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'attempt_id');
    }

    public function test_100_validation_missing_grade_entries(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
            ]);

        $this->assertValidationError($response, 'grade_entries');
    }

    public function test_100_validation_empty_grade_entries(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [],
            ]);

        $this->assertValidationError($response, 'grade_entries');
    }

    public function test_100_validation_missing_assessment_item_id(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grade_entries.0.assessment_item_id');
    }

    public function test_100_validation_invalid_assessment_item_id(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => 999999,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grade_entries.0.assessment_item_id');
    }

    public function test_100_validation_missing_score(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grade_entries.0.score');
    }

    public function test_100_validation_negative_score(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'score' => -5,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grade_entries.0.score');
    }

    public function test_100_validation_non_numeric_score(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'score' => 'not-a-number',
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grade_entries.0.score');
    }

    public function test_100_validation_missing_max_score(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'score' => 8,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grade_entries.0.max_score');
    }

    public function test_100_validation_negative_max_score(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'score' => 8,
                        'max_score' => -1,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grade_entries.0.max_score');
    }

    public function test_100_validation_non_boolean_is_draft(): void
    {
        $attempt = $this->setupPendingAttempt(1);
        $itemId = $this->firstItemId($this->assessment->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => 'invalid',
                'grade_entries' => [
                    [
                        'assessment_item_id' => $itemId,
                        'score' => 8,
                        'max_score' => 10,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'is_draft');
    }

    // ========================================================================
    // ResubmitRequest validation — POST /api/assessments/{assessmentId}/attempts/{attemptId}/resubmit (#103)
    // ========================================================================

    public function test_103_validation_missing_reason(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", []);

        $this->assertValidationError($response, 'reason');
    }

    public function test_103_validation_empty_reason(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => '',
            ]);

        $this->assertValidationError($response, 'reason');
    }

    public function test_103_validation_reason_too_long(): void
    {
        $attempt = $this->setupPendingAttempt(1);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/assessments/{$this->assessment->id}/attempts/{$attempt->id}/resubmit", [
                'reason' => str_repeat('a', 1001),
            ]);

        $this->assertValidationError($response, 'reason');
    }

    // ========================================================================
    // BulkGradeRequest validation — POST /api/grades/bulk (#104)
    // ========================================================================

    public function test_104_validation_missing_assessment_id(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', []);

        $this->assertValidationError($response, 'assessment_id');
    }

    public function test_104_validation_invalid_assessment_id(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => 999999,
            ]);

        $this->assertValidationError($response, 'assessment_id');
    }

    public function test_104_validation_missing_grades(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
            ]);

        $this->assertValidationError($response, 'grades');
    }

    public function test_104_validation_empty_grades(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [],
            ]);

        $this->assertValidationError($response, 'grades');
    }

    public function test_104_validation_missing_student_id(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $itemId,
                                'score' => 8,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.student_id');
    }

    public function test_104_validation_invalid_student_id(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => 999999,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $itemId,
                                'score' => 8,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.student_id');
    }

    public function test_104_validation_missing_grade_entries_in_bulk(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.grade_entries');
    }

    public function test_104_validation_empty_grade_entries_in_bulk(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [],
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.grade_entries');
    }

    public function test_104_validation_missing_assessment_item_id_in_bulk(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'score' => 8,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.grade_entries.0.assessment_item_id');
    }

    public function test_104_validation_invalid_assessment_item_id_in_bulk(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => 999999,
                                'score' => 8,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.grade_entries.0.assessment_item_id');
    }

    public function test_104_validation_negative_score_in_bulk(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $itemId,
                                'score' => -5,
                                'max_score' => 10,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.grade_entries.0.score');
    }

    public function test_104_validation_missing_max_score_in_bulk(): void
    {
        $itemId = $this->setupSingleItemAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/grades/bulk', [
                'assessment_id' => $this->assessment->id,
                'grades' => [
                    [
                        'student_id' => $this->student->id,
                        'grade_entries' => [
                            [
                                'assessment_item_id' => $itemId,
                                'score' => 8,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->assertValidationError($response, 'grades.0.grade_entries.0.max_score');
    }
}
