<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeEntry;
use App\Models\GradeLevel;
use App\Models\MasteryRecord;
use App\Models\Section;
use App\Models\SchoolYear;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Exceptions\BusinessRuleConflictException;
use App\Services\ClassroomService;
use App\Services\GradingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

#[Group('phase5-integration')]
class Phase5IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected GradingService $gradingService;

    private ?Subject $subject = null;

    private ?Classroom $classroom = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classroom = null;
        $this->gradingService = $this->app->make(GradingService::class);
    }

    private function setUpOrg(): array
    {
        $year = SchoolYear::create(['name' => 'SY 2025-INT']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2025-06-01',
            'end_date' => '2025-08-31',
        ]);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '7']);
        $section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-INT',
        ]);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-INT']);
        $competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-ALG-01',
            'descriptor' => 'Linear equations',
            'subject_id' => $this->subject->id,
            'grade_level' => '7',
        ]);

        $teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $student = User::factory()->create(['role' => 'Student', 'must_change_password' => false]);
        $otherTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now(),
        ]);

        return compact('semester', 'gradeLevel', 'section', 'competencyTag', 'teacher', 'student', 'otherTeacher') + ['subject' => $this->subject];
    }

    private function createAssessmentWithItem(array $data): Assessment
    {
        $assessment = Assessment::create(array_merge($data, [
            'classroom_id' => $this->classroom->id,
        ]));
        AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'essay',
            'prompt' => 'Explain your reasoning.',
            'max_points' => 10,
            'correct_answer' => null,
            'competency_tag_id' => $data['competency_tag_id'] ?? null,
            'sort_order' => 1,
        ]);

        return $assessment->fresh();
    }

    /**
     * Submit an essay assessment via the student HTTP flow.
     * The item is essay type, so the attempt stays pending_grading after submit.
     */
    private function submitEssayAssessment(Assessment $assessment, User $student): AssessmentAttempt
    {
        Sanctum::actingAs($student);
        $this->post("/api/student/assessments/{$assessment->id}/start");
        $this->post("/api/student/assessments/{$assessment->id}/submit", [
            'responses' => [(string) $assessment->items()->first()->id => 'Student essay response.'],
        ]);

        return AssessmentAttempt::where('assessment_id', $assessment->id)->first();
    }

    /** @test */
    public function test_full_lifecycle_from_submission_to_manual_grade_to_mastery()
    {
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);
        $this->assertEquals('pending_grading', $attempt->status);

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');

        $attempt->refresh();
        $this->assertEquals('scored', $attempt->status);

        $this->assertDatabaseHas('grade_entries', [
            'assessment_item_id' => $item->id,
            'score' => 8,
            'is_draft' => false,
        ]);

        $submission = $attempt->submission;
        $this->assertEquals('scored', $submission->status);
    }

    /** @test */
    public function test_regrade_recompute_is_blocked_by_unique_derivation_guard()
    {
        // R-11 (Phase 2 family A): the regrade's mastery recompute re-derives the
        // same (student, competency, assessment, submission) tuple that the
        // recordManualGrade flow already persisted — the ARCH-004 §7 UNIQUE
        // guard (ARCH-004 §7) now blocks it and the entire regrade transaction rolls
        // back (grade overwrite + duplicate derivation are both discarded).
        // Recompute semantics are a Phase 3 blocking dependency (regrade-mastery
        // semantics to be redefined; corrections flow via resubmission ARCH-002 FR-018).
        // R-01.
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);

        $this->actingAs($org['teacher'], 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10],
                ],
            ]);

        $submission = $attempt->submission()->first();
        $this->assertEquals(1, MasteryRecord::where('assessment_submission_id', $submission->id)->count());

        try {
            $this->gradingService->regradeAttempt($attempt->id, [
                [
                    'questionId' => $item->id,
                    'score' => 5,
                    'maxScore' => 10,
                ],
            ], $org['teacher']->id);
            $this->fail('Expected QueryException: regrade recompute must be blocked by the UNIQUE derivation guard.');
        } catch (QueryException $e) {
            // Expected: uq_mr_stu_comp_assess_submission (ARCH-004 §7, ARCH-004 §7).
            $this->assertInstanceOf(QueryException::class, $e);
        }

        // No second derivation for the tuple, and the ledger is untouched
        // (the aborted regrade transaction rolled back the grade overwrite).
        $this->assertEquals(1, MasteryRecord::where('assessment_submission_id', $submission->id)->count());

        $gradeEntry = GradeEntry::where('assessment_item_id', $item->id)->first();
        $this->assertEquals(8, $gradeEntry->score);
    }

    /** @test */
    public function test_regrade_requires_scored_status()
    {
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);

        $this->expectException(\App\Exceptions\BusinessRuleConflictException::class);
        $this->gradingService->regradeAttempt($attempt->id, [
            [
                'questionId' => $item->id,
                'score' => 5,
                'maxScore' => 10,
            ],
        ], $org['teacher']->id);
    }

    /** @test */
    public function test_regrade_by_non_owner_teacher_throws_403()
    {
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);

        $this->actingAs($org['teacher'], 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10],
                ],
            ]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->gradingService->regradeAttempt($attempt->id, [
            [
                'questionId' => $item->id,
                'score' => 5,
                'maxScore' => 10,
            ],
        ], $org['otherTeacher']->id);
    }

    /** @test */
    public function test_regrade_foreign_item_returns_409_item_not_in_assessment()
    {
        // ARCH-002 FR-018: regradeAttempt must reject an item that does not belong to
        // the attempt's assessment BEFORE writing any grade_entries row.
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);

        $this->actingAs($org['teacher'], 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10],
                ],
            ])
            ->assertOk();

        // Second assessment (same teacher) whose essay item is foreign.
        $foreignAssessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Foreign Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $foreignItem = $foreignAssessment->items()->first();

        try {
            $this->gradingService->regradeAttempt($attempt->id, [
                [
                    'questionId' => $foreignItem->id,
                    'score' => 5,
                    'maxScore' => 10,
                ],
            ], $org['teacher']->id);
            $this->fail('Expected BusinessRuleConflictException: regrade must reject foreign items.');
        } catch (BusinessRuleConflictException $e) {
            $this->assertSame('ITEM_NOT_IN_ASSESSMENT', $e->errorCode);
        }

        // No ledger row for the foreign item; the original grade is untouched.
        $this->assertSame(0, GradeEntry::where('assessment_item_id', $foreignItem->id)->count());
        $this->assertSame(8.0, (float) GradeEntry::where('assessment_item_id', $item->id)->first()->score);
    }

    /** @test */
    public function test_manual_grade_by_non_owner_teacher_returns_403()
    {
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);

        $response = $this->actingAs($org['otherTeacher'], 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 8, 'max_score' => 10],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_draft_mode_does_not_trigger_mastery_recompute()
    {
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'is_draft' => true,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 5, 'max_score' => 10],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');

        $this->assertDatabaseMissing('mastery_records', [
            'student_id' => $org['student']->id,
        ]);
    }

    /** @test */
    public function test_score_exceeds_maximum_returns_422()
    {
        $org = $this->setUpOrg();

        $assessment = $this->createAssessmentWithItem([
            'teacher_id' => $org['teacher']->id,
            'subject_id' => $org['subject']->id,
            'semester_id' => $org['semester']->id,
            'title' => 'Essay Test',
            'type' => 'Recorded',
            'status' => 'released',
            'competency_tag_id' => $org['competencyTag']->id,
        ]);
        $item = $assessment->items()->first();

        $attempt = $this->submitEssayAssessment($assessment, $org['student']);

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->postJson('/api/grades/manual', [
                'attempt_id' => $attempt->id,
                'grade_entries' => [
                    ['assessment_item_id' => $item->id, 'score' => 15, 'max_score' => 10],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
    }

    /** @test */
    public function test_nonexistent_student_mastery_summary_returns_404()
    {
        $org = $this->setUpOrg();

        $response = $this->actingAs($org['teacher'], 'sanctum')
            ->getJson('/api/mastery/records/999999/summary');

        $response->assertStatus(404);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }
}
