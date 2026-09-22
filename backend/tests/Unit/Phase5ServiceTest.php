<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
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
use App\Services\CompetencyMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 *
 * Service-layer unit tests for CompetencyMappingService.
 *
 * Focuses on edge cases NOT covered by Phase5Test.php (which covers
 * computeMastery thresholds, multi-competency grouping, zero-point skip,
 * determinism, Recorded/Unrecorded divergence, autoScore basics, and
 * basic endpoint success paths).
 *
 * This file covers:
 *   - getCurrentMasteryStatus: default-return when no records (NOT null)
 *   - getCurrentMasteryStatus: returns latest record (most recent wins)
 *   - computeMastery: 79.99% boundary → Not_Mastered
 *   - computeMastery: overall percentage when multiple competencies
 *   - computeMastery: Unrecorded assessment → no persistence (return only)
 *   - autoScoreObjectiveItems: case-insensitive comparison
 *   - autoScoreObjectiveItems: blank/null response → 0 points
 *   - autoScoreObjectiveItems: all-correct → full credit map
  *   - getNotCompetentFlags (derived view `v_not_competent_flags`): empty when
  *     no flags; scoped to subject_ids; only Not_Mastered latest rows
  *     produce flags; scoped to empty list → empty results
 *   - getAggregateSummaries: empty results when no mastery records
 *   - getAggregateSummaries: section-level filter
 *   - WU-9 (ARCH-004 §4.4/ARCH-002 FR-020/ARCH-002 FR-022/ARCH-004 §4.4): ratios ≤ 100, identical-created_at
 *     tiebreak → higher id wins, derived-view parity, latest-record-only flags
 *   - getMasteryHistory: empty results when no records
 *   - getMasteryHistory: returns all competencies for student
 *   - getClassLevelReport: empty section (no mastery records)
 *   - getClassLevelReport: multiple students with different statuses
 */
#[Group('phase5-service')]
class Phase5ServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompetencyMappingService $service;

    private User $teacher;
    private User $student;
    private User $student2;

    private Subject $subject;
    private Section $section;
        private Classroom $classroom;
    private CompetencyReference $competencyTag1;
    private CompetencyReference $competencyTag2;
    private Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompetencyMappingService::class);
        $this->setUpFixtures();
    }

    protected function setUpFixtures(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2025-SVC']);
        $term = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2025-06-01',
            'end_date' => '2025-08-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $term->id, 'grade_level' => '7']);

        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-SVC']);

        $this->teacher = User::factory()->create(['role' => 'Teacher']);
        $this->student = User::factory()->create([
            'role' => 'Student']);
        $this->student2 = User::factory()->create([
            'role' => 'Student']);

        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-SVC']);
        

        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'joined_at' => now()]);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student2->id,
            'joined_at' => now()]);

        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-ALG-01',
            'descriptor' => 'Linear equations',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GEO-02',
            'descriptor' => 'Geometry',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $this->assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $term->id,
            'title' => 'SVC Test',
            'type' => 'Recorded',
            'status' => 'released']);
    }

    /**
     * Create a submission with auto-scored objective items.
     * Returns the AssessmentSubmission.
     */
    private function createObjectiveSubmission(
        Assessment $assessment,
        User $student,
        array $responses, // [itemId => responseText]
    ): AssessmentSubmission {
        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);

        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'section_id' => $this->section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);

        $items = $assessment->items()->orderBy('sort_order')->get();

        foreach ($items as $item) {
            $responseText = $responses[$item->id] ?? null;

            if (in_array($item->item_type, ['multiple_choice', 'true_false'])) {
                $isCorrect = $responseText !== null
                    && strtolower(trim($responseText)) === strtolower(trim($item->correct_answer));
                $earned = $isCorrect ? (float) $item->max_points : 0.0;

                AssessmentResponse::create([
                    'submission_id' => $submission->id,
                    'item_id' => $item->id,
                    'response_text' => $responseText,
                    'earned_points' => $earned,
                    'is_auto_scored' => true]);
            } else {
                AssessmentResponse::create([
                    'submission_id' => $submission->id,
                    'item_id' => $item->id,
                    'response_text' => $responseText,
                    'earned_points' => null,
                    'is_auto_scored' => false]);
            }
        }

        $attempt->status = 'scored';
        $attempt->save();

        return $submission;
    }

    // ========================================================================
    // getCurrentMasteryStatus
    // ========================================================================

    /** @test */
    public function test_get_current_mastery_status_returns_default_when_no_records()
    {
        // Service returns default array, NOT null.
        $result = $this->service->getCurrentMasteryStatus(
            $this->student->id,
            $this->subject->id,
            $this->competencyTag1->id
        );

        $this->assertIsArray($result);
        $this->assertEquals(0.0, $result['mastery_percent']);
        $this->assertEquals('Not_Mastered', $result['mastery_status']);
        $this->assertNull($result['last_assessed_at']);
    }

    /** @test */
    public function test_get_current_mastery_status_returns_latest_record()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        // First submission: 0/10 → Not_Mastered (older).
        $sub1 = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'X']);
        $this->service->computeMastery($sub1->id, $this->subject->id);

        // Second submission: 10/10 → Mastered (latest).
        $attempt2 = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'response_history' => []]);
        $sub2 = AssessmentSubmission::create([
            'attempt_id' => $attempt2->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);
        AssessmentResponse::create([
            'submission_id' => $sub2->id,
            'item_id' => $item->id,
            'response_text' => 'A',
            'earned_points' => 10.0,
            'is_auto_scored' => true]);
        $attempt2->status = 'scored';
        $attempt2->save();
        $this->service->computeMastery($sub2->id, $this->subject->id);

        $result = $this->service->getCurrentMasteryStatus(
            $this->student->id,
            $this->subject->id,
            $this->competencyTag1->id
        );

        $this->assertEquals('Mastered', $result['mastery_status']);
        $this->assertEquals(100.0, $result['mastery_percent']);
        $this->assertNotNull($result['last_assessed_at']);
    }

    // ========================================================================
    // computeMastery: boundary and return-shape tests
    // ========================================================================

    /** @test */
    public function test_compute_mastery_79_99_percent_is_not_mastered()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 100,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        // 79.99/100 = 79.99% → below 80% threshold → Not_Mastered.
        $attempt = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);
        AssessmentResponse::create([
            'submission_id' => $submission->id,
            'item_id' => $item->id,
            'response_text' => 'A',
            'earned_points' => 79.99,
            'is_auto_scored' => true]);
        $attempt->status = 'scored';
        $attempt->save();

        $result = $this->service->computeMastery($submission->id, $this->subject->id);

        $this->assertEquals('Not_Mastered', $result['competency_results'][0]['mastery_status']);
        $this->assertEquals(79.99, $result['competency_results'][0]['mastery_percent']);
        $this->assertContains($this->competencyTag1->id, $result['unmastered_competency_ids']);
    }

    /** @test */
    public function test_compute_mastery_returns_overall_percentage_across_competencies()
    {
        $item1 = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);
        $item2 = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q2',
            'max_points' => 10,
            'correct_answer' => 'B',
            'competency_tag_id' => $this->competencyTag2->id,
            'sort_order' => 2]);

        // Q1: 10/10 → 100%, Q2: 5/10 → 50%
        // Overall: 15/20 = 75%
        $attempt = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);

        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item1->id,
            'response_text' => 'A', 'earned_points' => 10.0, 'is_auto_scored' => true]);
        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item2->id,
            'response_text' => 'X', 'earned_points' => 5.0, 'is_auto_scored' => true]);

        $attempt->status = 'scored';
        $attempt->save();

        $result = $this->service->computeMastery($submission->id, $this->subject->id);

        // Overall: 15/20 = 75%
        $this->assertEquals(15.0, $result['overall_score']);
        $this->assertEquals(20.0, $result['max_score']);
        $this->assertEquals(75.0, $result['percentage']);
        $this->assertCount(2, $result['competency_results']);
    }

    /** @test */
    public function test_compute_mastery_unrecorded_does_not_persist()
    {
        // R-11 (Phase 2 family A): assessments.type is immutable at the DB layer
        // (ARCH-004 §7), so the Unrecorded assessment is created directly instead of
        // flipping the Recorded setUp fixture's type.
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $this->assessment->semester_id,
            'title' => 'Unrecorded SVC Test',
            'type' => 'Unrecorded',
            'status' => 'released']);

        $item = AssessmentItem::create([
            'assessment_id' => $assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);
        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item->id,
            'response_text' => 'A', 'earned_points' => 10.0, 'is_auto_scored' => true]);
        $attempt->status = 'scored';
        $attempt->save();

        // Unrecorded: computeMastery returns results but does NOT persist.
        $result = $this->service->computeMastery($submission->id, $this->subject->id);

        $this->assertEquals('computed', $result['status']);
        $this->assertCount(1, $result['competency_results']);
        $this->assertEquals('Mastered', $result['competency_results'][0]['mastery_status']);

        // No records or flags persisted for Unrecorded (ARCH-002 FR-021, ARCH-002 FR-021).
        $this->assertEquals(0, MasteryRecord::count());
        $this->assertEquals(0, DB::table('v_not_competent_flags')->count());
    }

    // ========================================================================
    // autoScoreObjectiveItems
    // ========================================================================

    /** @test */
    public function test_auto_score_case_insensitive_comparison()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'true_false',
            'prompt' => 'Q1',
            'max_points' => 5,
            'correct_answer' => 'True',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);

        // Student enters 'TRUE' (different case) — should match 'True'.
        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item->id,
            'response_text' => 'TRUE', 'earned_points' => null, 'is_auto_scored' => false]);

        $scores = $this->service->autoScoreObjectiveItems($submission->id);

        $this->assertEquals(5.0, $scores[$item->id]);
    }

    /** @test */
    public function test_auto_score_blank_response_returns_zero()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);

        // No response stored at all — blank submission.
        $scores = $this->service->autoScoreObjectiveItems($submission->id);

        $this->assertEquals(0.0, $scores[$item->id]);
    }

    /** @test */
    public function test_auto_score_all_correct_returns_full_credit_map()
    {
        $item1 = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);
        $item2 = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'true_false',
            'prompt' => 'Q2',
            'max_points' => 5,
            'correct_answer' => 'true',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 2]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);

        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item1->id,
            'response_text' => 'A', 'earned_points' => null, 'is_auto_scored' => false]);
        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item2->id,
            'response_text' => 'true', 'earned_points' => null, 'is_auto_scored' => false]);

        $scores = $this->service->autoScoreObjectiveItems($submission->id);

        $this->assertEquals(10.0, $scores[$item1->id]);
        $this->assertEquals(5.0, $scores[$item2->id]);
    }

    /** @test */
    public function test_auto_score_excludes_essay_items()
    {
        // Essay items should not appear in the result map.
        $essayItem = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'essay',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => null,
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);

        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $essayItem->id,
            'response_text' => 'Essay text', 'earned_points' => null, 'is_auto_scored' => false]);

        $scores = $this->service->autoScoreObjectiveItems($submission->id);

        $this->assertArrayNotHasKey($essayItem->id, $scores);
        $this->assertEmpty($scores);
    }

    // ========================================================================
    // getNotCompetentFlags
    // ========================================================================

    /** @test */
    public function test_get_not_competent_flags_returns_empty_when_no_flags()
    {
        $flags = $this->service->getNotCompetentFlags([$this->subject->id]);

        $this->assertIsArray($flags);
        $this->assertCount(0, $flags);
    }

    /** @test */
    public function test_get_not_competent_flags_returns_only_not_mastered()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        // Student 1: 0/10 → Not_Mastered → flag created.
        $sub1 = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'X']);
        $this->service->computeMastery($sub1->id, $this->subject->id);

        // Student 2: 10/10 → Mastered → no flag.
        $sub2 = $this->createObjectiveSubmission($this->assessment, $this->student2, [$item->id => 'A']);
        $this->service->computeMastery($sub2->id, $this->subject->id);

        $flags = $this->service->getNotCompetentFlags([$this->subject->id]);

        $this->assertCount(1, $flags);
        $this->assertEquals($this->student->id, $flags[0]['student_id']);
        $this->assertEquals($this->competencyTag1->id, $flags[0]['competency_id']);
    }

    /** @test */
    public function test_get_not_competent_flags_unscoped_returns_all()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $sub = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'X']);
        $this->service->computeMastery($sub->id, $this->subject->id);

        // Unscoped (null) returns all flags across all sections.
        $flags = $this->service->getNotCompetentFlags(null);

        $this->assertCount(1, $flags);
    }

    /** @test */
    public function test_get_not_competent_flags_scoped_to_empty_list_returns_empty()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $sub = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'X']);
        $this->service->computeMastery($sub->id, $this->subject->id);

        // Scoped with empty array → no matching sections → empty result.
        $flags = $this->service->getNotCompetentFlags([]);

        $this->assertIsArray($flags);
        $this->assertCount(0, $flags);
    }

    // ========================================================================
    // getAggregateSummaries
    // ========================================================================

    /** @test */
    public function test_get_aggregate_summaries_empty_when_no_records()
    {
        $result = $this->service->getAggregateSummaries([$this->section->id]);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /** @test */
    public function test_get_aggregate_summaries_section_level_filter()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $sub = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'A']);
        $this->service->computeMastery($sub->id, $this->subject->id);

        $result = $this->service->getAggregateSummaries([$this->section->id]);

        $this->assertCount(1, $result);
        $this->assertEquals($this->competencyTag1->id, $result[0]['competency_id']);
        $this->assertEquals(1, $result[0]['mastered_count']);
        $this->assertEquals(0, $result[0]['not_mastered_count']);
        $this->assertEquals(100.0, $result[0]['mastery_rate_percent']);
    }

    /** @test */
    public function test_get_aggregate_summaries_mixed_mastered_not_mastered()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        // Student 1: correct → Mastered. Student 2: wrong → Not_Mastered.
        $sub1 = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'A']);
        $this->service->computeMastery($sub1->id, $this->subject->id);

        $sub2 = $this->createObjectiveSubmission($this->assessment, $this->student2, [$item->id => 'X']);
        $this->service->computeMastery($sub2->id, $this->subject->id);

        $result = $this->service->getAggregateSummaries([$this->section->id]);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['mastered_count']);
        $this->assertEquals(1, $result[0]['not_mastered_count']);
        $this->assertEquals(50.0, $result[0]['mastery_rate_percent']);
        $this->assertEquals(50.0, $result[0]['remediation_frequency']);
    }

    // ========================================================================
    // WU-9 — ARCH-004 §4.4 (ratios ≤ 100), ARCH-002 FR-020 (tiebreak), ARCH-004 §4.4 (view)
    // ========================================================================

    /** @test */
    public function test_mastery_rate_percent_never_exceeds_100()
    {
        // Two Recorded assessments on the SAME competency, both Mastered:
        // raw record count (2) > distinct-student count (1). The aggregate
        // must resolve the latest-per-(student, competency) record FIRST so
        // mastery_rate_percent stays ≤ 100 (ARCH-004 §4.4).
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $assessment2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $this->assessment->semester_id,
            'title' => 'SVC Test 2',
            'type' => 'Recorded',
            'status' => 'released']);
        $item2 = AssessmentItem::create([
            'assessment_id' => $assessment2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $sub1 = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'A']);
        $this->service->computeMastery($sub1->id, $this->subject->id);
        $sub2 = $this->createObjectiveSubmission($assessment2, $this->student, [$item2->id => 'A']);
        $this->service->computeMastery($sub2->id, $this->subject->id);

        $result = $this->service->getAggregateSummaries([$this->section->id]);

        $this->assertCount(1, $result);
        // The latest-per-pair set has exactly ONE record for the pair.
        $this->assertEquals(1, $result[0]['total_students_assessed']);
        $this->assertEquals(1, $result[0]['mastered_count']);
        $this->assertEquals(100.0, $result[0]['mastery_rate_percent']);
    }

    /** @test */
    public function test_remediation_frequency_never_exceeds_100()
    {
        // Two Recorded assessments on the SAME competency, both Not_Mastered:
        // raw record count (2) > distinct-student count (1). remediation_frequency
        // must stay ≤ 100 (ARCH-004 §4.4).
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $assessment2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $this->assessment->semester_id,
            'title' => 'SVC Test 2',
            'type' => 'Recorded',
            'status' => 'released']);
        $item2 = AssessmentItem::create([
            'assessment_id' => $assessment2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $sub1 = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'X']);
        $this->service->computeMastery($sub1->id, $this->subject->id);
        $sub2 = $this->createObjectiveSubmission($assessment2, $this->student, [$item2->id => 'X']);
        $this->service->computeMastery($sub2->id, $this->subject->id);

        $result = $this->service->getAggregateSummaries([$this->section->id]);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['total_students_assessed']);
        $this->assertEquals(1, $result[0]['not_mastered_count']);
        $this->assertEquals(100.0, $result[0]['remediation_frequency']);
    }

    /** @test */
    public function test_tiebreak_identical_created_at_higher_id_wins()
    {
        // Two mastery records per (student, competency) with IDENTICAL
        // created_at resolve deterministically to the HIGHER id in every
        // report/derivation (ARCH-002 FR-020, ARCH-002 FR-020).
        //  - student:  assessment1 Mastered (lower id), assessment2 Not_Mastered (higher id)
        //  - student2: assessment1 Not_Mastered (lower id), assessment2 Mastered (higher id)
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $assessment2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $this->assessment->semester_id,
            'title' => 'SVC Test 2',
            'type' => 'Recorded',
            'status' => 'released']);
        $item2 = AssessmentItem::create([
            'assessment_id' => $assessment2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $this->service->computeMastery(
            $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'A'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($this->assessment, $this->student2, [$item->id => 'X'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($assessment2, $this->student, [$item2->id => 'X'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($assessment2, $this->student2, [$item2->id => 'A'])->id,
            $this->subject->id
        );

        $records = MasteryRecord::query()
            ->where('competency_id', $this->competencyTag1->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $records);
        $studentLatest = $records->where('student_id', $this->student->id)->last();
        $student2Latest = $records->where('student_id', $this->student2->id)->last();
        $this->assertTrue($studentLatest->id > $records->where('student_id', $this->student->id)->first()->id);
        $this->assertTrue($student2Latest->id > $records->where('student_id', $this->student2->id)->first()->id);

        // Force IDENTICAL created_at across all four records.
        MasteryRecord::query()->update(['created_at' => '2026-08-16 10:00:00']);

        // Higher id wins everywhere: student → Not_Mastered, student2 → Mastered.
        $summary = $this->service->getAggregateSummaries([$this->section->id]);
        $this->assertEquals(1, $summary[0]['mastered_count']);
        $this->assertEquals(1, $summary[0]['not_mastered_count']);
        $this->assertEquals(50.0, $summary[0]['mastery_rate_percent']);
        $this->assertEquals(50.0, $summary[0]['remediation_frequency']);

        $analytics = app(\App\Services\AnalyticsService::class);
        $analyticsSummary = $analytics->getAggregateCompetencySummary([$this->section->id]);
        $this->assertEquals(1, $analyticsSummary[0]['mastered_count']);
        $this->assertEquals(1, $analyticsSummary[0]['not_mastered_count']);

        $flags = $this->service->getNotCompetentFlags([$this->subject->id]);
        $this->assertCount(1, $flags);
        $this->assertEquals($studentLatest->id, $flags[0]['mastery_record_id']);

        $view = DB::table('v_not_competent_flags')->get();
        $this->assertCount(1, $view);
        $this->assertEquals($studentLatest->id, (int) $view->first()->mastery_record_id);

        $report = $this->service->getClassLevelReport($this->section->id);
        $this->assertCount(1, $report['competency_reports']);
        $this->assertEquals(1, $report['competency_reports'][0]['mastered_count']);
        $this->assertEquals(1, $report['competency_reports'][0]['not_mastered_count']);
    }

    /** @test */
    public function test_parity_derived_view_matches_stored_flags_before_drop()
    {
        // Snapshot fixture: each (student, competency) carries exactly ONE
        // Not_Mastered record — the shape where the append-only stored table
        // and the derived view MUST produce identical rows (ARCH-004 §4.4 parity
        // snapshot, written RED before the drop lands).
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $assessment2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $this->assessment->semester_id,
            'title' => 'SVC Test 2',
            'type' => 'Recorded',
            'status' => 'released']);
        $item2 = AssessmentItem::create([
            'assessment_id' => $assessment2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q2',
            'max_points' => 10,
            'correct_answer' => 'B',
            'competency_tag_id' => $this->competencyTag2->id,
            'sort_order' => 1]);

        // student:  comp1 Not_Mastered (flag), comp2 Mastered (no flag).
        $this->service->computeMastery(
            $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'X'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($assessment2, $this->student, [$item2->id => 'B'])->id,
            $this->subject->id
        );
        // student2: comp1 Mastered (no flag), comp2 Not_Mastered (flag).
        $this->service->computeMastery(
            $this->createObjectiveSubmission($this->assessment, $this->student2, [$item->id => 'A'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($assessment2, $this->student2, [$item2->id => 'X'])->id,
            $this->subject->id
        );

        // PHP-side derivation of the expected flag rows: the latest Recorded
        // record per (student, competency) with mastery_status = Not_Mastered
        // (ARCH-002 FR-020, ARCH-002 FR-020 tiebreak).
        $expected = MasteryRecord::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn ($r) => $r->student_id . '|' . $r->competency_id)
            ->map(fn ($records) => $records->first())
            ->filter(fn ($r) => $r->mastery_status === 'Not_Mastered')
            ->map(fn ($r) => [
                'student_id' => (int) $r->student_id,
                'competency_id' => (int) $r->competency_id,
                'assessment_submission_id' => (int) $r->assessment_submission_id,
                'mastery_record_id' => (int) $r->id])
            ->values()
            ->sortBy('mastery_record_id')
            ->values()
            ->all();

        $this->assertCount(2, $expected);

        // Snapshot parity: while the stored table still exists, the CURRENT
        // writer's rows must equal the derivation exactly (RED-phase
        // evidence — asserted before the drop lands).
        if (Schema::hasTable('not_competent_flags')) {
            $stored = DB::table('not_competent_flags')
                ->orderBy('id')
                ->get()
                ->map(fn ($f) => [
                    'student_id' => (int) $f->student_id,
                    'competency_id' => (int) $f->competency_id,
                    'assessment_submission_id' => (int) $f->assessment_submission_id,
                    'mastery_record_id' => (int) $f->mastery_record_id])
                ->values()
                ->sortBy('mastery_record_id')
                ->values()
                ->all();

            $this->assertEquals($expected, $stored);
        }

        // The derived view must expose exactly the same rows (drop-in).
        $view = DB::table('v_not_competent_flags')
            ->orderBy('id')
            ->get()
            ->map(fn ($v) => [
                'student_id' => (int) $v->student_id,
                'competency_id' => (int) $v->competency_id,
                'assessment_submission_id' => (int) $v->assessment_submission_id,
                'mastery_record_id' => (int) $v->mastery_record_id])
            ->values()
            ->sortBy('mastery_record_id')
            ->values()
            ->all();

        $this->assertEquals($expected, $view);
    }

    /** @test */
    public function test_not_competent_flag_view_latest_record_only()
    {
        // ARCH-004 §4.4 semantics: the view resolves the LATEST Recorded record per
        // (student, competency) and flags only if that record is Not_Mastered.
        //  - student:  older Mastered → newer Not_Mastered → flagged.
        //  - student2: older Not_Mastered → newer Mastered → NOT flagged
        //    (the stored table WOULD keep the stale flag — the view cannot).
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $assessment2 = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $this->assessment->semester_id,
            'title' => 'SVC Test 2',
            'type' => 'Recorded',
            'status' => 'released']);
        $item2 = AssessmentItem::create([
            'assessment_id' => $assessment2->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        $this->service->computeMastery(
            $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'A'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($assessment2, $this->student, [$item2->id => 'X'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($this->assessment, $this->student2, [$item->id => 'X'])->id,
            $this->subject->id
        );
        $this->service->computeMastery(
            $this->createObjectiveSubmission($assessment2, $this->student2, [$item2->id => 'A'])->id,
            $this->subject->id
        );

        // Force explicit ordering: pair T1 (older) vs T2 (newer).
        $t1 = '2026-08-15 09:00:00';
        $t2 = '2026-08-16 09:00:00';
        MasteryRecord::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $this->assessment->id)
            ->update(['created_at' => $t1]);
        MasteryRecord::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $assessment2->id)
            ->update(['created_at' => $t2]);
        MasteryRecord::query()
            ->where('student_id', $this->student2->id)
            ->where('assessment_id', $this->assessment->id)
            ->update(['created_at' => $t1]);
        MasteryRecord::query()
            ->where('student_id', $this->student2->id)
            ->where('assessment_id', $assessment2->id)
            ->update(['created_at' => $t2]);

        $flags = $this->service->getNotCompetentFlags([$this->subject->id]);

        // Only student (newer Not_Mastered) is flagged; student2's stale
        // Not_Mastered (superseded by Mastered) must NOT be flagged.
        $this->assertCount(1, $flags);
        $this->assertEquals($this->student->id, $flags[0]['student_id']);

        $latestStudentRecord = MasteryRecord::query()
            ->where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $this->assertEquals($latestStudentRecord->id, $flags[0]['mastery_record_id']);
        $this->assertEquals(
            $latestStudentRecord->id,
            (int) DB::table('v_not_competent_flags')->value('mastery_record_id')
        );
    }

    // ========================================================================
    // getMasteryHistory
    // ========================================================================

    /** @test */
    public function test_get_mastery_history_empty_when_no_records()
    {
        $history = $this->service->getMasteryHistory(
            $this->student->id,
            $this->subject->id,
            $this->competencyTag1->id
        );

        $this->assertIsArray($history);
        $this->assertCount(0, $history);
    }

    /** @test */
    public function test_get_mastery_history_returns_all_competencies()
    {
        $item1 = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);
        $item2 = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q2',
            'max_points' => 10,
            'correct_answer' => 'B',
            'competency_tag_id' => $this->competencyTag2->id,
            'sort_order' => 2]);

        // Single submission: comp1 correct (Mastered), comp2 wrong (Not_Mastered).
        $attempt = AssessmentAttempt::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'response_history' => []]);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attempt->id,
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $this->assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'scored']);
        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item1->id,
            'response_text' => 'A', 'earned_points' => 10.0, 'is_auto_scored' => true]);
        AssessmentResponse::create([
            'submission_id' => $submission->id, 'item_id' => $item2->id,
            'response_text' => 'X', 'earned_points' => 0.0, 'is_auto_scored' => true]);
        $attempt->status = 'scored';
        $attempt->save();

        $this->service->computeMastery($submission->id, $this->subject->id);

        // History without competency filter → both records.
        $history = $this->service->getMasteryHistory(
            $this->student->id,
            $this->subject->id
        );

        $this->assertCount(2, $history);

        // History with competency filter → only that competency.
        $history = $this->service->getMasteryHistory(
            $this->student->id,
            $this->subject->id,
            $this->competencyTag1->id
        );

        $this->assertCount(1, $history);
        $this->assertEquals($this->competencyTag1->id, $history[0]['competency_id']);
    }

    // ========================================================================
    // getClassLevelReport
    // ========================================================================

    /** @test */
    public function test_get_class_level_report_empty_when_no_records()
    {
        $result = $this->service->getClassLevelReport($this->section->id);

        $this->assertEquals($this->section->id, $result['section_id']);
        $this->assertEquals(2, $result['total_students']);
        $this->assertIsArray($result['competency_reports']);
        $this->assertCount(0, $result['competency_reports']);
    }

    /** @test */
    public function test_get_class_level_report_multiple_students()
    {
        $item = AssessmentItem::create([
            'assessment_id' => $this->assessment->id,
            'item_type' => 'multiple_choice',
            'prompt' => 'Q1',
            'max_points' => 10,
            'correct_answer' => 'A',
            'competency_tag_id' => $this->competencyTag1->id,
            'sort_order' => 1]);

        // Student 1: correct → Mastered. Student 2: wrong → Not_Mastered.
        $sub1 = $this->createObjectiveSubmission($this->assessment, $this->student, [$item->id => 'A']);
        $this->service->computeMastery($sub1->id, $this->subject->id);

        $sub2 = $this->createObjectiveSubmission($this->assessment, $this->student2, [$item->id => 'X']);
        $this->service->computeMastery($sub2->id, $this->subject->id);

        $result = $this->service->getClassLevelReport($this->section->id);

        $this->assertEquals(2, $result['total_students']);
        $this->assertCount(1, $result['competency_reports']);
        $this->assertEquals(1, $result['competency_reports'][0]['mastered_count']);
        $this->assertEquals(1, $result['competency_reports'][0]['not_mastered_count']);
    }
}
