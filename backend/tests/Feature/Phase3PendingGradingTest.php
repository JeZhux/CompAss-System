<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
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

#[Group('phase3-pending-grading')]
class Phase3PendingGradingTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    
    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

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
    }

    /**
     * Helper: create an assessment with the given items, then release it.
     * Returns the Assessment model (with items loaded).
     */
    private function createReleasedAssessment(array $items): Assessment
    {
        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Pending Grading Test',
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
     * Helper: student starts an assessment and submits responses.
     * Returns the AssessmentSubmission (pending_grading).
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

    // ========================================================================
    // #62 — Teacher Lists Pending Grading
    // ========================================================================

    public function test_teacher_lists_pending_grading_empty(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/pending-grading');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_teacher_lists_pending_grading_populated(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $item = $assessment->items()->orderBy('sort_order')->first();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $item->id => 'Student essay response.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/pending-grading');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $submission->id);
        $response->assertJsonPath('data.0.student_name', $this->student->name);
        $response->assertJsonPath('data.0.assessment_id', $assessment->id);
        $response->assertJsonPath('data.0.attempt_id', $submission->attempt_id);
    }

    // ========================================================================
    // #63 — Teacher Gets Pending Items
    // ========================================================================

    public function test_teacher_gets_pending_items_returns_only_subjective(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
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
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
            ],
        ]);

        $items = $assessment->items()->orderBy('sort_order')->get();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $items[0]->id => 'B',
            (string) $items[1]->id => 'Essay response.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/submissions/{$submission->id}/pending-items");

        $response->assertOk();
        $response->assertJsonPath('data.submission_id', $submission->id);
        $response->assertJsonPath('data.status', 'pending_grading');
        $this->assertCount(1, $response->json('data.pending_items'));
        $response->assertJsonPath('data.pending_items.0.item_type', 'essay');
        $response->assertJsonPath('data.pending_items.0.item_id', $items[1]->id);
        $response->assertJsonPath('data.pending_items.0.max_points', 15);
        $response->assertJsonPath('data.pending_items.0.student_response', 'Essay response.');
    }

    public function test_teacher_gets_pending_items_all_scored_returns_empty(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $item = $assessment->items()->orderBy('sort_order')->first();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $item->id => 'Essay response.',
        ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item->id => 12,
                ],
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/submissions/{$submission->id}/pending-items");

        $response->assertOk();
        $this->assertCount(0, $response->json('data.pending_items'));
    }

    // ========================================================================
    // #64 — Teacher Scores Subjective Items
    // ========================================================================

    public function test_teacher_scores_all_subjective_moves_to_scored(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Question 1.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
            [
                'item_type' => 'essay',
                'prompt' => 'Question 2.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
            ],
        ]);

        $items = $assessment->items()->orderBy('sort_order')->get();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $items[0]->id => 'Answer 1.',
            (string) $items[1]->id => 'Answer 2.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $items[0]->id => 8,
                    (string) $items[1]->id => 12,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');
        $response->assertJsonPath('data.mastery_results.status', 'computed');
        $this->assertDatabaseHas('assessment_submissions', [
            'id' => $submission->id,
            'status' => 'scored',
        ]);
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $submission->id,
            'item_id' => $items[0]->id,
            'earned_points' => 8.0,
        ]);
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $submission->id,
            'item_id' => $items[1]->id,
            'earned_points' => 12.0,
        ]);
    }

    public function test_teacher_scores_partial_stays_pending_grading(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Question 1.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
            [
                'item_type' => 'essay',
                'prompt' => 'Question 2.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
            ],
        ]);

        $items = $assessment->items()->orderBy('sort_order')->get();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $items[0]->id => 'Answer 1.',
            (string) $items[1]->id => 'Answer 2.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $items[0]->id => 8,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');
        $this->assertDatabaseHas('assessment_submissions', [
            'id' => $submission->id,
            'status' => 'pending_grading',
        ]);
    }

    public function test_teacher_scores_objective_item_returns_409_not_a_subjective_item(): void
    {
        $this->setUpOrg();

        // Assessment has both objective and subjective items so the
        // submission lands in pending_grading (has at least one essay).
        $assessment = $this->createReleasedAssessment([
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
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2,
            ],
        ]);

        $items = $assessment->items()->orderBy('sort_order')->get();
        $objectiveItem = $items[0];

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $items[0]->id => 'B',
            (string) $items[1]->id => 'Essay response.',
        ]);

        // Attempt to score the objective (multiple_choice) item — should fail.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $objectiveItem->id => 8,
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_A_SUBJECTIVE_ITEM');
    }

    public function test_teacher_scores_above_max_returns_409_score_exceeds_maximum(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $item = $assessment->items()->orderBy('sort_order')->first();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $item->id => 'Essay response.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item->id => 15,
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'SCORE_EXCEEDS_MAXIMUM');
    }

    public function test_teacher_scores_non_pending_submission_returns_409_not_pending_grading(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $item = $assessment->items()->orderBy('sort_order')->first();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $item->id => 'Essay response.',
        ]);

        // Score all items — submission transitions to 'scored'.
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item->id => 8,
                ],
            ]);

        // Attempt to score again — submission is now 'scored', not 'pending_grading'.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item->id => 9,
                ],
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_PENDING_GRADING');
    }

    // ========================================================================
    // #65 — Teacher Releases Results
    // ========================================================================

    public function test_teacher_releases_results_after_scoring_done(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $item = $assessment->items()->orderBy('sort_order')->first();

        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $item->id => 'Essay response.',
        ]);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item->id => 8,
                ],
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Results released.');
        // ARCH-005 block 4.5: ai_explanations_triggered semantics are deleted entirely — the
        // HTTP payload must not carry the key anywhere.
        $this->assertArrayNotHasKey('ai_explanations_triggered', $response->json());
        $this->assertArrayNotHasKey('ai_explanations_triggered', $response->json('data'));
        // results_released_at mirrors the persisted submission timestamp.
        $persistedAt = AssessmentSubmission::query()
            ->where('id', $submission->id)
            ->value('results_released_at');
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($response->json('data.results_released_at'))
                ->equalTo(\Illuminate\Support\Carbon::parse($persistedAt))
        );
        $this->assertDatabaseHas('assessment_submissions', [
            'id' => $submission->id,
            'is_results_released' => true,
        ]);
    }

    public function test_teacher_releases_results_with_pending_grading_returns_409(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 15,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $this->studentStartAndSubmit($assessment->id, [
            1 => 'Essay response.',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'PENDING_GRADING_BLOCK');
    }

    public function test_teacher_releases_results_on_unreleased_assessment_returns_409(): void
    {
        $this->setUpOrg();

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'title' => 'Unreleased Assessment',
                'description' => '',
                'type' => 'Recorded',
            ])
            ->json('data');

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/items", [
                'item_type' => 'essay',
                'prompt' => 'Question.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release-results");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ASSESSMENT_NOT_RELEASED');
    }

    public function test_teacher_releases_results_for_other_teachers_assessment_returns_404(): void
    {
        $this->setUpOrg();

        // Create a second teacher who does NOT own the assessment.
        $otherTeacher = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false,
        ]);

        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Explain your reasoning.',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $item = $assessment->items()->orderBy('sort_order')->first();

        $this->studentStartAndSubmit($assessment->id, [
            (string) $item->id => 'Essay response.',
        ]);

        $response = $this->actingAs($otherTeacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertStatus(404);
    }

    // ========================================================================
    // Full End-to-End Workflow
    // ========================================================================

    public function test_full_e2e_pending_grading_workflow(): void
    {
        $this->setUpOrg();

        // 1. Teacher creates assessment with an essay item and releases it.
        $assessment = $this->createReleasedAssessment([
            [
                'item_type' => 'essay',
                'prompt' => 'Write an essay about your topic.',
                'max_points' => 20,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1,
            ],
        ]);

        $item = $assessment->items()->orderBy('sort_order')->first();

        // 2. Student starts and submits an essay answer.
        $submission = $this->studentStartAndSubmit($assessment->id, [
            (string) $item->id => 'This is my essay answer.',
        ]);

        $this->assertDatabaseHas('assessment_submissions', [
            'id' => $submission->id,
            'status' => 'pending_grading',
        ]);

        // 3. Teacher lists pending grading and finds the submission.
        $listResponse = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/pending-grading');

        $listResponse->assertOk();
        $this->assertCount(1, $listResponse->json('data'));
        $listResponse->assertJsonPath('data.0.id', $submission->id);
        $listResponse->assertJsonPath('data.0.student_name', $this->student->name);

        // 4. Teacher views the pending subjective items.
        $pendingItemsResponse = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/submissions/{$submission->id}/pending-items");

        $pendingItemsResponse->assertOk();
        $this->assertCount(1, $pendingItemsResponse->json('data.pending_items'));
        $pendingItemsResponse->assertJsonPath('data.pending_items.0.item_type', 'essay');

        // 5. Teacher scores the essay item — submission transitions to scored.
        $scoreResponse = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $item->id => 18,
                ],
            ]);

        $scoreResponse->assertOk();
        $scoreResponse->assertJsonPath('data.status', 'scored');

        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $submission->id,
            'item_id' => $item->id,
            'earned_points' => 18.0,
        ]);

        // 6. Teacher releases results.
        $releaseResponse = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment->id}/release-results");

        $releaseResponse->assertOk();
        $releaseResponse->assertJsonPath('data.message', 'Results released.');
        // ARCH-005 block 4.5: ai_explanations_triggered semantics are deleted entirely — the
        // HTTP payload must not carry the key anywhere.
        $this->assertArrayNotHasKey('ai_explanations_triggered', $releaseResponse->json());
        $this->assertArrayNotHasKey('ai_explanations_triggered', $releaseResponse->json('data'));
        // results_released_at mirrors the persisted submission timestamp.
        $persistedAt = AssessmentSubmission::query()
            ->where('id', $submission->id)
            ->value('results_released_at');
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($releaseResponse->json('data.results_released_at'))
                ->equalTo(\Illuminate\Support\Carbon::parse($persistedAt))
        );

        $this->assertDatabaseHas('assessment_submissions', [
            'id' => $submission->id,
            'is_results_released' => true,
        ]);

        // 7. Student views results.
        $resultsResponse = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/assessments/{$assessment->id}/results");

        $resultsResponse->assertOk();
        $resultsResponse->assertJsonPath('data.title', 'Pending Grading Test');
        $resultsResponse->assertJsonPath('data.type', 'Recorded');
        $this->assertEquals(18, $resultsResponse->json('data.overall_score'));
        $this->assertEquals(20, $resultsResponse->json('data.max_score'));
        $this->assertEquals(18.0, (float) $resultsResponse->json('data.items.0.earned_points'));
    }
}
