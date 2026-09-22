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
use App\Models\GradeEntry;
use App\Models\GradeLevel;
use App\Models\ImportJob;
use App\Models\LearningMaterial;
use App\Models\MasteryRecord;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\ClassroomService;
use App\Services\CompetencyMappingService;
use App\Services\GradingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

#[Group('phase5')]
class Phase5Test extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    private ?User $otherStudent = null;

    private ?User $admin = null;

    private ?User $otherTeacher = null;

    
    private ?Section $section = null;

    private ?Subject $subject = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag1 = null;

    private ?CompetencyReference $competencyTag2 = null;

    private ?CompetencyReference $competencyTag3 = null;

    private ?Assessment $assessment = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->student = null;
        $this->otherStudent = null;
        $this->admin = null;
        $this->otherTeacher = null;
        $this->section = null;
        $this->subject = null;
        $this->classroom = null;
        $this->competencyTag1 = null;
        $this->competencyTag2 = null;
        $this->competencyTag3 = null;
        $this->assessment = null;
    }

    private function actAsTeacher(): User
    {
        if (! $this->teacher) {
            $this->teacher = User::factory()->create([
                'role' => 'Teacher',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->teacher);

        return $this->teacher;
    }

    private function actAsStudent(): User
    {
        if (! $this->student) {
            $this->student = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->student);

        return $this->student;
    }

    private function actAsOtherStudent(): User
    {
        if (! $this->otherStudent) {
            $this->otherStudent = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->otherStudent);

        return $this->otherStudent;
    }

    private function actAsAdmin(): User
    {
        if (! $this->admin) {
            $this->admin = User::factory()->create([
                'role' => 'Admin',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    /**
     * Build a full org hierarchy with THREE competency tags for testing
     * competency grouping in computeMastery.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P5']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P5']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P5']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GE-002',
            'descriptor' => 'Classify geometric figures',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag3 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-STAT-003',
            'descriptor' => 'Interpret statistical data',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $teacher = $this->actAsTeacher();

        $student = $this->actAsStudent();
        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now()]);
    }

    /**
     * Create a released assessment with given items.
     */
    private function createReleasedAssessment(string $type, array $items): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Phase 5 ' . $type . ' Test',
            'description' => '',
            'type' => $type,
            'status' => 'draft']);

        $sortOrder = 1;
        foreach ($items as $itemData) {
            AssessmentItem::create([
                'assessment_id' => $assessment->id,
                'item_type' => $itemData['item_type'],
                'prompt' => $itemData['prompt'],
                'max_points' => $itemData['max_points'],
                'correct_answer' => $itemData['correct_answer'] ?? null,
                'competency_tag_id' => $itemData['competency_tag_id'],
                'sort_order' => $sortOrder++]);
        }

        $assessment->status = 'released';
        $assessment->save();

        return $assessment->fresh();
    }

    /**
     * Student starts and submits an objective assessment.
     * Response keys are mapped positionally to actual item IDs so tests
     * are resilient to auto-increment gaps across tests (PostgreSQL
     * RefreshDatabase uses transactions, not truncation).
     */
    private function studentStartAndSubmit(int $assessmentId, array $responses): AssessmentSubmission
    {
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        // Map positional response values to actual item IDs.
        $items = AssessmentItem::where('assessment_id', $assessmentId)
            ->orderBy('sort_order')
            ->get();
        $mappedResponses = [];
        $i = 0;
        foreach ($responses as $responseText) {
            if (isset($items[$i])) {
                $mappedResponses[(string) $items[$i]->id] = $responseText;
            }
            $i++;
        }

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => $mappedResponses]);

        return AssessmentSubmission::where('assessment_id', $assessmentId)
            ->where('status', 'scored')
            ->first();
    }

    // ========================================================================
    // Unit tests: computeMastery (ARCH-002 FR-020 — Per-Competency Mastery Calculation)
    // ========================================================================

    public function test_compute_mastery_threshold_boundary_mastered_at_80_percent(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Math Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Math Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Math Q3',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Math Q4',
                'max_points' => 10,
                'correct_answer' => 'D',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Math Q5',
                'max_points' => 10,
                'correct_answer' => 'E',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // 40/50 = 80% — exactly at threshold = Mastered (ARCH-002 FR-020).
        $submission = $this->studentStartAndSubmit($assessment->id, [
            '1' => 'A', // correct → 10
            '2' => 'B', // correct → 10
            '3' => 'C', // correct → 10
            '4' => 'D', // correct → 10
            '5' => 'X', // wrong → 0
        ]);

        $records = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->get();

        $this->assertCount(1, $records);
        $this->assertEquals(80.00, (float) $records->first()->mastery_percent);
        $this->assertEquals('Mastered', $records->first()->mastery_status);
    }

    public function test_compute_mastery_threshold_boundary_mastered_at_99_percent(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 99,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 1,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // 99/100 = 99% → Mastered
        $this->studentStartAndSubmit($assessment->id, [
            '1' => 'A',
            '2' => 'X']);

        $record = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals('Mastered', $record->mastery_status);
        $this->assertEquals(99.00, (float) $record->mastery_percent);
    }

    public function test_compute_mastery_not_mastered_below_threshold_creates_flag(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // 0/20 = 0% → Not_Mastered → flag created
        $this->studentStartAndSubmit($assessment->id, [
            '1' => 'X',
            '2' => 'X']);

        $records = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->get();

        $this->assertCount(1, $records);
        $this->assertEquals('Not_Mastered', $records->first()->mastery_status);
        $this->assertEquals(0.00, (float) $records->first()->mastery_percent);

        $flags = DB::table('v_not_competent_flags')
            ->where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->get();

        $this->assertCount(1, $flags);
    }

    public function test_compute_mastery_groups_items_by_competency(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1 (comp1)',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2 (comp1)',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q3 (comp2)',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag2->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q4 (comp2)',
                'max_points' => 10,
                'correct_answer' => 'D',
                'competency_tag_id' => $this->competencyTag2->id]]);

        // Comp1: 10/20 = 50% → Not_Mastered
        // Comp2: 20/20 = 100% → Mastered
        $this->studentStartAndSubmit($assessment->id, [
            '1' => 'A', // correct → 10 (comp1: 10)
            '2' => 'X', // wrong → 0 (comp1: 10)
            '3' => 'C', // correct → 10 (comp2: 10)
            '4' => 'D', // correct → 10 (comp2: 10)
        ]);

        $comp1Records = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->get();

        $comp2Records = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag2->id)
            ->get();

        $this->assertCount(1, $comp1Records);
        $this->assertEquals('Not_Mastered', $comp1Records->first()->mastery_status);
        $this->assertEquals(50.00, (float) $comp1Records->first()->mastery_percent);

        $this->assertCount(1, $comp2Records);
        $this->assertEquals('Mastered', $comp2Records->first()->mastery_status);
        $this->assertEquals(100.00, (float) $comp2Records->first()->mastery_percent);

        // Comp1 should be flagged by the derived view, comp2 should not.
        $this->assertEquals(1, DB::table('v_not_competent_flags')->where('competency_id', $this->competencyTag1->id)->count());
        $this->assertEquals(0, DB::table('v_not_competent_flags')->where('competency_id', $this->competencyTag2->id)->count());
    }

    // ========================================================================
    // Unit tests: zero-point competency skip (ARCH-002 FR-020)
    // ========================================================================

    public function test_compute_mastery_zero_point_competency_is_skipped(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1 (comp1, 0 points)',
                'max_points' => 0,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2 (comp2, 10 points)',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag2->id]]);

        $this->studentStartAndSubmit($assessment->id, [
            '1' => 'A',
            '2' => 'B']);

        // Comp1 (0 max points) → NO mastery record (zero-point skip, ARCH-002 FR-020).
        $this->assertEquals(0, MasteryRecord::where('competency_id', $this->competencyTag1->id)->count());

        // Comp2 (0/10 correct, 100%) → mastery record with Mastered.
        $comp2Record = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag2->id)
            ->first();

        $this->assertNotNull($comp2Record);
        $this->assertEquals('Mastered', $comp2Record->mastery_status);
    }

    // ========================================================================
    // Unit tests: Recorded vs. Unrecorded divergence (ARCH-002 FR-021)
    // ========================================================================

    public function test_recorded_assessment_persists_mastery_records(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmit($assessment->id, [
            '1' => 'A']);

        $this->assertDatabaseHas('mastery_records', [
            'student_id' => $this->student->id,
            'competency_id' => $this->competencyTag1->id,
            'assessment_id' => $assessment->id,
            'mastery_status' => 'Mastered']);
    }

    public function test_unrecorded_assessment_does_not_persist_mastery_records(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Unrecorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");

        $item = $assessment->items()->orderBy('sort_order')->first();
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [(string) $item->id => 'A']]);

        // Unrecorded → no mastery_records, no derived flags (ARCH-002 FR-021, ARCH-002 FR-021).
        $this->assertEquals(0, MasteryRecord::where('student_id', $this->student->id)->count());
        $this->assertEquals(0, DB::table('v_not_competent_flags')->where('student_id', $this->student->id)->count());
    }

    // ========================================================================
    // Unit tests: determinism (ARCH-002 FR-020)
    // ========================================================================

    public function test_compute_mastery_is_deterministic(): void
    {
        $this->setUpOrg();

        // Two identical Recorded assessments → two independent derivations with
        // identical inputs must yield identical mastery results (ARCH-002 FR-020).
        $itemsConfig = [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag2->id]];
        $assessment1 = $this->createReleasedAssessment('Recorded', $itemsConfig);
        $assessment2 = $this->createReleasedAssessment('Recorded', $itemsConfig);

        // R-11 (Phase 2 family A): the old test submitted the SAME assessment
        // twice and re-called computeMastery on the same submission — a second
        // attempt is blocked by the ALREADY_SUBMITTED guard, and re-deriving the
        // same (student, competency, assessment, submission) tuple now trips the
        // ARCH-004 §7 UNIQUE guard (ARCH-004 §7). Determinism is instead proven
        // across two identical assessments, each derived exactly once by the
        // submit flow (the single-derivation path).
        $items1 = $assessment1->items()->orderBy('sort_order')->get();
        $responses = [
            (string) $items1[0]->id => 'A',
            (string) $items1[1]->id => 'B'];

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment1->id}/start");
        $submit1 = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment1->id}/submit", [
                'responses' => $responses]);
        $result1 = $submit1->json('data.mastery_results');

        $items2 = $assessment2->items()->orderBy('sort_order')->get();
        $responses2 = [
            (string) $items2[0]->id => 'A',
            (string) $items2[1]->id => 'B'];

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment2->id}/start");
        $submit2 = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment2->id}/submit", [
                'responses' => $responses2]);
        $result2 = $submit2->json('data.mastery_results');

        $this->assertEquals($result1['competency_results'], $result2['competency_results']);
        $this->assertEquals($result1['unmastered_competency_ids'], $result2['unmastered_competency_ids']);
    }

    // ========================================================================
    // Unit tests: autoScoreObjectiveItems (ARCH-001 §5.1)
    // ========================================================================

    public function test_auto_score_objective_items(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'true_false',
                'prompt' => 'Q2',
                'max_points' => 5,
                'correct_answer' => 'true',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'essay',
                'prompt' => 'Q3',
                'max_points' => 15,
                'correct_answer' => null,
                'competency_tag_id' => $this->competencyTag2->id]]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");

        $items = $assessment->items()->orderBy('sort_order')->get();

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [
                    (string) $items[0]->id => 'A',    // correct → 10
                    (string) $items[1]->id => 'false', // wrong → 0
                    (string) $items[2]->id => 'Essay',  // essay, not objective
                ]]);

        $submission = AssessmentSubmission::where('assessment_id', $assessment->id)
            ->where('status', 'pending_grading')
            ->first();

        $service = $this->app->make(CompetencyMappingService::class);
        $scores = $service->autoScoreObjectiveItems($submission->id);

        $this->assertEquals(10.0, $scores[$items[0]->id]);
        $this->assertEquals(0.0, $scores[$items[1]->id]);
        // Essay items excluded from auto-scoring.
        $this->assertArrayNotHasKey($items[2]->id, $scores);
    }

    // ========================================================================
    // Integration: Grading triggers mastery (Phase 3/4 stub replaced)
    // ========================================================================

    public function test_teacher_grading_essay_triggers_mastery_computation(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'essay',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => null,
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");

        $items = $assessment->items()->orderBy('sort_order')->get();

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [(string) $items[0]->id => 'Student essay response.']]);

        $submission = AssessmentSubmission::where('assessment_id', $assessment->id)
            ->where('status', 'pending_grading')
            ->first();

        // Teacher grades the essay.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $items[0]->id => 8]]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');
        $response->assertJsonPath('data.mastery_results.status', 'computed');

        // Mastery record should be created: 8/10 = 80% → Mastered.
        $this->assertDatabaseHas('mastery_records', [
            'student_id' => $this->student->id,
            'competency_id' => $this->competencyTag1->id,
            'mastery_status' => 'Mastered']);
    }

    public function test_teacher_grading_essay_below_threshold_creates_not_competent_flag(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'essay',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => null,
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");

        $items = $assessment->items()->orderBy('sort_order')->get();

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [(string) $items[0]->id => 'Student essay response.']]);

        $submission = AssessmentSubmission::where('assessment_id', $assessment->id)
            ->where('status', 'pending_grading')
            ->first();

        // Grade: 5/10 = 50% → Not_Mastered.
        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/submissions/{$submission->id}/score", [
                'scores' => [
                    (string) $items[0]->id => 5]]);

        $this->assertDatabaseHas('mastery_records', [
            'student_id' => $this->student->id,
            'competency_id' => $this->competencyTag1->id,
            'mastery_status' => 'Not_Mastered']);

        // ARCH-004 §4.4: Not-Competent is a derived view (ARCH-004 §4.4) — the
        // latest-per-(student, competency) Recorded row that is Not_Mastered
        // surfaces as a flag row with the same drop-in columns.
        $this->assertDatabaseHas('v_not_competent_flags', [
            'student_id' => $this->student->id,
            'competency_id' => $this->competencyTag1->id]);
    }

    // ========================================================================
    // Endpoint tests: #73 — GET /api/teacher/not-competent-flags
    // ========================================================================

    public function test_73_teacher_lists_not_competent_flags(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Student scores 0/10 → Not_Mastered → flag.
        $this->studentStartAndSubmit($assessment->id, ['1' => 'X']);

        $this->actAsOtherStudent();
        ClassroomEnrollment::firstOrCreate([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id]);

        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");
        $item = $assessment->items()->orderBy('sort_order')->first();
        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [(string) $item->id => 'A'], // correct → Mastered, no flag
            ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/not-competent-flags?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->student->id, $response->json('data.0.student_id'));
        $this->assertEquals($this->competencyTag1->id, $response->json('data.0.competency_id'));
    }

    public function test_73_teacher_not_competent_flags_without_section(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmit($assessment->id, ['1' => 'X']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/not-competent-flags');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_73_student_cannot_access_not_competent_flags(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/teacher/not-competent-flags');

        $response->assertStatus(403);
    }

    public function test_73_unauthenticated_returns_401(): void
    {
        $response = $this->get('/api/teacher/not-competent-flags');

        $response->assertStatus(401);
    }

    // ========================================================================
    // Endpoint tests: #74 — GET /api/teacher/competency-summary
    // ========================================================================

    public function test_74_teacher_gets_competency_summary(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag2->id]]);

        // Student A: 10/10 + 0/10 → comp1 Mastered, comp2 Not_Mastered.
        $items = $assessment->items()->orderBy('sort_order')->get();
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [
                    (string) $items[0]->id => 'A',
                    (string) $items[1]->id => 'X']]);

        // Student B: 0/10 + 10/10 → comp1 Not_Mastered, comp2 Mastered.
        $this->actAsOtherStudent();
        ClassroomEnrollment::firstOrCreate([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->otherStudent->id]);
        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");
        $this->actingAs($this->otherStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [
                    (string) $items[0]->id => 'X',
                    (string) $items[1]->id => 'B']]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/competency-summary?subject_id=' . $this->subject->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_74_student_cannot_access_competency_summary(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/teacher/competency-summary');

        $response->assertStatus(403);
    }

    // ========================================================================
    // Endpoint tests: #75 — GET /api/teacher/sections/{sectionId}/class-level-report
    // ========================================================================

    public function test_75_teacher_gets_class_level_report(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/sections/{$this->section->id}/class-level-report");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($this->section->id, $data['section_id']);
        $this->assertNotEmpty($data['competency_reports']);
        $this->assertEquals(1, $data['competency_reports'][0]['mastered_count']);
    }

    public function test_75_teacher_not_assigned_to_section_returns_403(): void
    {
        $this->setUpOrg();

        // Create a second section with a different teacher.
        $year = SchoolYear::create(['name' => 'SY 2026-P5-2']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2027-01-01',
            'end_date' => '2027-03-31']);
        $gradeLevel = GradeLevel::create(['semester_id' => $semester->id, 'grade_level' => '8']);
        $otherSection = Section::create(['grade_level_id' => $gradeLevel->id, 'name' => '8B-P5']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/sections/{$otherSection->id}/class-level-report");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'SECTION_NOT_ASSIGNED');
    }

    public function test_75_nonexistent_section_returns_404(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/sections/99999/class-level-report');

        $response->assertStatus(404);
    }

    // ========================================================================
    // Endpoint tests: #76 — GET /api/admin/competency-summary
    // ========================================================================

    public function test_76_admin_gets_competency_summary(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);

        $admin = $this->actAsAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/admin/competency-summary');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_76_admin_gets_summary_filtered_by_grade_level(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);

        $admin = $this->actAsAdmin();
        $gradeLevelId = $this->section->grade_level_id;

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/admin/competency-summary?grade_level_id={$gradeLevelId}");

        $response->assertOk();
    }

    public function test_76_teacher_cannot_access_admin_competency_summary(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/admin/competency-summary');

        $response->assertStatus(403);
    }

    public function test_76_student_cannot_access_admin_competency_summary(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/admin/competency-summary');

        $response->assertStatus(403);
    }

    public function test_76_unauthenticated_returns_401(): void
    {
        $response = $this->get('/api/admin/competency-summary');

        $response->assertStatus(401);
    }

    // ========================================================================
    // Integration: #101/#102 mastery endpoints return real data
    // ========================================================================

    public function test_101_mastery_records_returns_real_data_after_scoring(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/mastery/records?student_id=' . $this->student->id . '&subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->competencyTag1->id, $response->json('data.0.competency_id'));
        $this->assertEquals('Mastered', $response->json('data.0.mastery_status'));
    }

    public function test_102_mastery_summary_returns_real_data_after_scoring(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag2->id]]);

        // Student scores 10/10 on comp1 (Mastered) and 0/10 on comp2 (Not_Mastered).
        $this->studentStartAndSubmit($assessment->id, ['1' => 'A', '2' => 'X']);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/mastery/records/{$this->student->id}/summary");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($this->student->id, $data['student_id']);
        $this->assertCount(2, $data['competencies']);
        $this->assertNotEquals('Not Yet Computed', $data['overall_level']);
    }

    // ========================================================================
    // Integration: releaseResults returns real unmastered competencies
    // ========================================================================

    public function test_release_results_returns_unmastered_competencies(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag2->id]]);

        // Student scores comp1=Mastered, comp2=Not_Mastered.
        $items = $assessment->items()->orderBy('sort_order')->get();
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [
                    (string) $items[0]->id => 'A',
                    (string) $items[1]->id => 'X']]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment->id}/release-results");

        $response->assertOk();
        $data = $response->json('data');
        // ARCH-005 block 4.5: the release payload carries service-authored values only —
        // ai_explanations_triggered semantics are deleted (ARCH-002 FR-024) and
        // results_released_at mirrors the persisted submission timestamp.
        $response->assertJsonStructure(['data' => ['message', 'results_released_at']]);
        $this->assertNotNull($data['results_released_at']);

        $persistedAt = AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->max('results_released_at');
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($data['results_released_at'])
                ->equalTo(\Illuminate\Support\Carbon::parse($persistedAt))
        );

        // ARCH-005 block 4.5: the service return value itself must not fabricate keys.
        $serviceResult = app(AssessmentService::class)->releaseResults(
            (int) $this->teacher->id,
            (int) $assessment->id
        );
        $this->assertArrayNotHasKey('ai_explanations_triggered', $serviceResult);
        $this->assertArrayHasKey('results_released_at', $serviceResult);

        // F-002 (M-048): the unmastered list is a service-level contract —
        // assert the release-path unmastered computation directly. Re-calling
        // releaseResults is now side-effect free for AI (lazy, ARCH-002 FR-024).
        $unmastered = (new \ReflectionMethod(
            AssessmentService::class,
            'getUnmasteredStudentCompetencies'
        ))->invoke(app(AssessmentService::class), (int) $assessment->id);
        $unmasteredIds = collect($unmastered)->pluck('competency_id')->all();
        $this->assertContains($this->competencyTag2->id, $unmasteredIds);
    }

    // ========================================================================
    // Unit test: MasteryRecord append-only — current status derived from most recent
    // ========================================================================

    public function test_mastery_history_retains_all_records_append_only(): void
    {
        $this->setUpOrg();

        // Assessment 1: student answers correctly → Mastered.
        $assessment1 = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $item1 = $assessment1->items()->orderBy('sort_order')->first();
        $this->studentStartAndSubmit($assessment1->id, [$item1->id => 'A']);

        // Assessment 2: same competency, student answers wrong → Not_Mastered.
        $assessment2 = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $item2 = $assessment2->items()->orderBy('sort_order')->first();
        $this->studentStartAndSubmit($assessment2->id, [$item2->id => 'X']);

        $records = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $records);
        $this->assertEquals('Mastered', $records[0]->mastery_status);
        $this->assertEquals('Not_Mastered', $records[1]->mastery_status);

        // Latest = Not_Mastered (ARCH-002 FR-020: current status = most recent).
        $history = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/mastery/records?student_id={$this->student->id}&competency_id={$this->competencyTag1->id}&subject_id={$this->subject->id}");

        $history->assertOk();
        $data = $history->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals('Not_Mastered', $data[0]['mastery_status']);
    }

    // ========================================================================
    // Unit test: NotCompetentFlag is a derived view (ARCH-004 §4.4, ARCH-004 §4.4)
    // ========================================================================

    public function test_not_competent_flag_deleted_with_parent_mastery_record(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmit($assessment->id, ['1' => 'X']);

        $masteryRecord = MasteryRecord::where('student_id', $this->student->id)
            ->where('competency_id', $this->competencyTag1->id)
            ->first();

        // The derived view exposes exactly one flag row for the Not_Mastered pair.
        $this->assertEquals(1, DB::table('v_not_competent_flags')->where('mastery_record_id', $masteryRecord->id)->count());

        // Delete the mastery record → the view row disappears (the view cannot
        // go stale — it derives from mastery_records, ARCH-004 §4.4).
        $masteryRecord->delete();

        $this->assertEquals(0, DB::table('v_not_competent_flags')->where('mastery_record_id', $masteryRecord->id)->count());
    }

    // ========================================================================
    // Phase 2 — Family A mastery_records DB-integrity guards (ARCH-004 §7, ARCH-004 §7)
    // Invariant tests written FIRST (RED), then the migration lands (GREEN).
    // ========================================================================

    public function test_mastery_unrecorded_insert_rejected_zero_rows(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Unrecorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);

        // ARCH-004 §7: a mastery insert sourced from an Unrecorded assessment must
        // be rejected at the DB layer — zero-row guarantee (ARCH-002 FR-021, ARCH-002 FR-021).
        // The failing statement aborts the PG transaction; the nested
        // DB::transaction rolls back to a savepoint so the count query below
        // still executes.
        try {
            DB::transaction(function () use ($assessment, $submission): void {
                MasteryRecord::create([
                    'student_id' => $this->student->id,
                    'subject_id' => $this->subject->id,
                    'classroom_id' => $this->classroom->id,
                    'assessment_id' => $assessment->id,
                    'assessment_submission_id' => $submission->id,
                    'competency_id' => $this->competencyTag1->id,
                    'mastery_percent' => 100.00,
                    'mastery_status' => 'Mastered']);
            });
            $this->fail('Expected QueryException: Unrecorded assessments must never persist mastery rows.');
        } catch (QueryException $e) {
            // Expected: trg_mr_recorded_only raises (ARCH-002 FR-021, ARCH-002 FR-021).
            $this->assertInstanceOf(QueryException::class, $e);
        }

        $this->assertEquals(0, MasteryRecord::count());
    }

    public function test_mastery_percent_range_check_violation(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Recorded submit persists a record for comp1; insert a >100% row for a
        // different competency so the percent CHECK (not the UNIQUE) fires.
        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);

        // ARCH-004 §7: CHECK (mastery_percent BETWEEN 0 AND 100).
        $this->expectException(QueryException::class);
        MasteryRecord::create([
            'student_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $submission->id,
            'competency_id' => $this->competencyTag2->id,
            'mastery_percent' => 101.00,
            'mastery_status' => 'Mastered']);
    }

    public function test_mastery_status_threshold_check_violation(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);

        $base = [
            'student_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $submission->id,
            'competency_id' => $this->competencyTag2->id];

        // ARCH-004 §7: status↔threshold CHECK — 90% with Not_Mastered and 50% with
        // Mastered both violate. Each violating statement aborts the PG
        // transaction, so each runs inside a nested DB::transaction that rolls
        // back to a savepoint and keeps the surrounding test transaction alive.
        foreach ([
            ['mastery_percent' => 90.00, 'mastery_status' => 'Not_Mastered'],
            ['mastery_percent' => 50.00, 'mastery_status' => 'Mastered']] as $violation) {
            try {
                DB::transaction(function () use ($base, $violation): void {
                    MasteryRecord::create($base + $violation);
                });
                $this->fail('Expected QueryException: status↔threshold CHECK violation.');
            } catch (QueryException $e) {
                // Expected: chk_mr_status_threshold (ARCH-004 §7).
                $this->assertInstanceOf(QueryException::class, $e);
            }
        }
    }

    public function test_mastery_unique_derivation_collision(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // First derivation persists a row during the Recorded submit.
        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);
        $this->assertEquals(1, MasteryRecord::count());

        // ARCH-004 §7: UNIQUE (student, competency, assessment, submission) blocks a
        // second derivation of the same tuple (ARCH-004 §7).
        $this->expectException(QueryException::class);
        MasteryRecord::create([
            'student_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'classroom_id' => $this->classroom->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $submission->id,
            'competency_id' => $this->competencyTag1->id,
            'mastery_percent' => 100.00,
            'mastery_status' => 'Mastered']);
    }

    public function test_assessments_type_immutable(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // ARCH-004 §7: assessments.type is immutable at the DB layer.
        $this->expectException(QueryException::class);
        DB::table('assessments')
            ->where('id', $assessment->id)
            ->update(['type' => 'Unrecorded']);
    }

    public function test_mastery_desc_index_exists(): void
    {
        $this->setUpOrg();

        $indexDef = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            ['mastery_records', 'idx_mr_stu_comp_created']
        );

        // ARCH-004 §7: current-mastery derivation index (student_id, competency_id,
        // created_at DESC) — ARCH-002 QA-001, ARCH-002 FR-020.
        $this->assertNotNull($indexDef, 'idx_mr_stu_comp_created must exist on mastery_records.');
        $this->assertStringContainsString('DESC', $indexDef->indexdef);
    }

    // ========================================================================
    // Phase 2 — Family B grade_entries DB-integrity guards (ARCH-004 §4.1)
    // Invariant tests written FIRST (RED), then the migration lands (GREEN).
    // ========================================================================

    /**
     * Create a pending_grading attempt for an essay assessment (item not
     * auto-scored, so the attempt/submission stay pending after submit).
     */
    private function submitEssayAttempt(Assessment $assessment): AssessmentAttempt
    {
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");
        $item = $assessment->items()->orderBy('sort_order')->first();
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [(string) $item->id => 'Student essay response.']]);

        return AssessmentAttempt::where('assessment_id', $assessment->id)->firstOrFail();
    }

    public function test_grade_entry_negative_score_check_violation(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);
        $item = $assessment->items()->orderBy('sort_order')->first();

        // ARCH-004 §4.1: CHECK (score >= 0) — chk_ge_score_non_negative (D-004
        // negative scores were the bypassable gap). The failing statement
        // aborts the PG transaction; the nested DB::transaction rolls back to a
        // savepoint so the count query below still executes.
        try {
            DB::transaction(function () use ($submission, $item): void {
                GradeEntry::create([
                    'assessment_submission_id' => $submission->id,
                    'assessment_item_id' => $item->id,
                    'score' => -1,
                    'max_score' => 10,
                    'graded_by' => $this->teacher->id,
                    'graded_at' => now(),
                    'is_draft' => false]);
            });
            $this->fail('Expected QueryException: negative scores must be rejected at the DB layer.');
        } catch (QueryException $e) {
            // Expected: chk_ge_score_non_negative (ARCH-004 §4.1).
            $this->assertInstanceOf(QueryException::class, $e);
        }

        $this->assertEquals(0, GradeEntry::count());
    }

    public function test_grade_entry_score_over_max_check_violation(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);
        $item = $assessment->items()->orderBy('sort_order')->first();

        // ARCH-004 §4.1: CHECK (score <= max_score) — chk_ge_score_not_over_max.
        try {
            DB::transaction(function () use ($submission, $item): void {
                GradeEntry::create([
                    'assessment_submission_id' => $submission->id,
                    'assessment_item_id' => $item->id,
                    'score' => 11,
                    'max_score' => 10,
                    'graded_by' => $this->teacher->id,
                    'graded_at' => now(),
                    'is_draft' => false]);
            });
            $this->fail('Expected QueryException: score above max_score must be rejected at the DB layer.');
        } catch (QueryException $e) {
            // Expected: chk_ge_score_not_over_max (ARCH-004 §4.1).
            $this->assertInstanceOf(QueryException::class, $e);
        }

        $this->assertEquals(0, GradeEntry::count());
    }

    public function test_grade_entry_max_score_snapshot_from_item(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A', '2' => 'B']);
        $items = $assessment->items()->orderBy('sort_order')->get();

        // ARCH-004 §4.1: trg_ge_snapshot_max_score — the authoritative
        // assessment_items.max_points snapshot wins over any client-sent
        // max_score (ARCH-004 §7, ARCH-004 §7(1)).
        GradeEntry::create([
            'assessment_submission_id' => $submission->id,
            'assessment_item_id' => $items[0]->id,
            'score' => 8,
            'max_score' => 999,
            'graded_by' => $this->teacher->id,
            'graded_at' => now(),
            'is_draft' => false]);

        $entry = GradeEntry::where('assessment_item_id', $items[0]->id)->firstOrFail();
        $this->assertEquals(10.00, (float) $entry->max_score);

        // A score of 11 (over the item max but ≤ 999) must now raise the
        // score <= max_score CHECK — the trigger feeds the CHECK the snapshot
        // before it validates (trigger-before-CHECK ordering).
        try {
            DB::transaction(function () use ($submission, $items): void {
                GradeEntry::create([
                    'assessment_submission_id' => $submission->id,
                    'assessment_item_id' => $items[1]->id,
                    'score' => 11,
                    'max_score' => 999,
                    'graded_by' => $this->teacher->id,
                    'graded_at' => now(),
                    'is_draft' => false]);
            });
            $this->fail('Expected QueryException: snapshot max_score must feed the score <= max_score CHECK.');
        } catch (QueryException $e) {
            // Expected: chk_ge_score_not_over_max after the trigger snapshots
            // max_score from the item (ARCH-004 §4.1).
            $this->assertInstanceOf(QueryException::class, $e);
        }

        $this->assertEquals(1, GradeEntry::count());
    }

    public function test_grade_entry_decimal_overflow_safe(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 12345.67,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $submission = $this->studentStartAndSubmit($assessment->id, ['1' => 'A']);
        $item = $assessment->items()->orderBy('sort_order')->first();

        // ARCH-004 §4.1: DECIMAL(8,2) — the ledger is overflow-safe beyond the old
        // 999.99 cap. The snapshot trigger overwrites max_score from the item
        // (12345.67), so the full row fits the new precision.
        GradeEntry::create([
            'assessment_submission_id' => $submission->id,
            'assessment_item_id' => $item->id,
            'score' => 12345.67,
            'max_score' => 12345.67,
            'graded_by' => $this->teacher->id,
            'graded_at' => now(),
            'is_draft' => false]);

        $entry = GradeEntry::where('assessment_item_id', $item->id)->firstOrFail();
        $this->assertEquals(12345.67, (float) $entry->score);
        $this->assertEquals(12345.67, (float) $entry->max_score);
    }

    public function test_grade_entry_negative_score_service_rejected(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'essay',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => null,
                'competency_tag_id' => $this->competencyTag1->id]]);
        $item = $assessment->items()->orderBy('sort_order')->first();
        $attempt = $this->submitEssayAttempt($assessment);
        $this->assertEquals('pending_grading', $attempt->status);

        // ARCH-004 §4.1: service-layer lower bound — negative scores are rejected
        // with a 422 VALIDATION_ERROR in recordManualGrade (ARCH-002 QA-007). Zero is
        // LEGAL (needed by Phase 3 ARCH-004 §4.1); only negative is rejected at the
        // service. The DB CHECK remains score >= 0 (ARCH-004 §4.1 prose
        // "negative/zero" is imprecise; decision recorded in open-questions).
        $service = $this->app->make(GradingService::class);

        try {
            $service->recordManualGrade($attempt->id, [
                [
                    'questionId' => $item->id,
                    'score' => -1,
                    'maxScore' => 10]], $this->teacher->id);
            $this->fail('Expected ValidationException: negative scores must be rejected at the service layer.');
        } catch (ValidationException $e) {
            // Expected: 422 VALIDATION_ERROR.
            $this->assertSame(422, $e->status);
        }

        // No grade_entry row persisted for the rejected negative score.
        $this->assertEquals(0, GradeEntry::count());
    }

    // ========================================================================
    // Phase 2 RIDERS batch (WU-C) — ARCH-002 FR-002/038/039/021/035/025
    // ========================================================================

    public function test_users_identifier_xor_check(): void
    {
        // Phase A CompAss-ID: every role identifies by school_id
        // (ADM/TEA/STU-XXXX-XXXXX, prefix must match role, no email column).
        $this->setUpOrg();

        // 1) Malformed school_id → format CHECK violation.
        $this->assertIdentifierXorRejected([
            'role' => 'Student']);

        // 2) NULL school_id → NOT NULL violation.
        $this->assertIdentifierXorRejected([
            'role' => 'Student',
            'school_id' => null]);

        // 3) Prefix mismatch (Teacher with STU- ID) → prefix CHECK violation.
        $this->assertIdentifierXorRejected([
            'role' => 'Teacher',
            'school_id' => 'STU-1234-56789']);

        // Conforming rows pass. The factory auto-enforces the invariant.
        $student = User::factory()->student()->create();
        $teacher = User::factory()->teacher()->create();
        $admin = User::factory()->admin()->create();
        $this->assertNotNull($student->id);
        $this->assertNotNull($teacher->id);
        $this->assertNotNull($admin->id);
    }

    private function assertIdentifierXorRejected(array $attributes): void
    {
        // Raw INSERT bypasses the UserFactory's conformance hook so the DB
        // CHECK itself is what rejects the row.
        try {
            DB::transaction(function () use ($attributes): void {
                DB::table('users')->insert(array_merge([
                    'name' => 'XOR Test User',
                    'password_hash' => bcrypt('password123'),
                    'must_change_password' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()], $attributes));
            });
            $this->fail('Expected QueryException: CompAss ID CHECK violation.');
        } catch (QueryException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
        }
    }

    public function test_submission_snapshot_mismatch_rejected(): void
    {
        // ARCH-004 §4.1: assessment_submissions.semester_id / .section_id must equal the
        // parent assessment's semester + its classroom's section (BASELINE
        // v1.2 §4.3.1 snapshot integrity). Enforced by trg_assess_sub_snapshot.
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // A second, EXISTING semester (valid FK) that differs from the assessment's.
        $year2 = SchoolYear::create(['name' => 'SY 2027-SNAP']);
        $otherTerm = Semester::create(['semester' => '1', 'school_year_id' => $year2->id,
            'name' => '2nd Trimester',
            'start_date' => '2027-01-01',
            'end_date' => '2027-03-31']);

        // A second, EXISTING section (valid FK) that differs from the
        // assessment's classroom's section.
        $otherSection = Section::create([
            'grade_level_id' => $this->section->grade_level_id,
            'name' => '7B-SNAP']);

        // Case A: matching section but a DIFFERENT term → rejected.
        $attemptA = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'submitted']);
        try {
            DB::transaction(function () use ($assessment, $attemptA, $otherTerm): void {
                AssessmentSubmission::create([
                    'attempt_id' => $attemptA->id,
                    'assessment_id' => $assessment->id,
                    'student_id' => $this->student->id,
                    'section_id' => $this->section->id,
                    'semester_id' => $otherTerm->id,
                    'submitted_at' => now(),
                    'status' => 'pending_grading']);
            });
            $this->fail('Expected QueryException: snapshot term mismatch must be rejected (ARCH-004 §4.1).');
        } catch (QueryException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
        }

        // Case B: matching term but a DIFFERENT section → rejected.
        $attemptB = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'submitted']);
        try {
            DB::transaction(function () use ($assessment, $attemptB, $otherSection): void {
                AssessmentSubmission::create([
                    'attempt_id' => $attemptB->id,
                    'assessment_id' => $assessment->id,
                    'student_id' => $this->student->id,
                    'section_id' => $otherSection->id,
                    'semester_id' => $assessment->semester_id,
                    'submitted_at' => now(),
                    'status' => 'pending_grading']);
            });
            $this->fail('Expected QueryException: snapshot section mismatch must be rejected (ARCH-004 §4.1).');
        } catch (QueryException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
        }

        // A conforming submission (matching term + section) passes.
        $attemptC = AssessmentAttempt::create([
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'attempt_number' => 3,
            'status' => 'submitted']);
        $submission = AssessmentSubmission::create([
            'attempt_id' => $attemptC->id,
            'assessment_id' => $assessment->id,
            'student_id' => $this->student->id,
            'section_id' => $this->section->id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now(),
            'status' => 'pending_grading']);
        $this->assertNotNull($submission->id);
    }

    public function test_dead_timestamp_columns_absent(): void
    {
        // ARCH-004 §4.1: the six dead timestamp columns were dropped (ARCH-004 §4.1).
        $expectedAbsent = [
            ['announcement_attachments', 'created_at'],
            ['assignment_attachments', 'created_at'],
            ['submission_files', 'created_at'],
            ['assessment_item_attachments', 'created_at'],
            ['assessment_responses', 'created_at'],
            ['assessment_auto_saves', 'updated_at']];

        foreach ($expectedAbsent as [$table, $column]) {
            $exists = DB::selectOne(
                'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                [$table, $column]
            );
            $this->assertNull($exists, "Column {$table}.{$column} must be absent (ARCH-004 §4.1).");
        }
    }

    public function test_import_admin_fk_restrict(): void
    {
        // ARCH-004 §4.1: import_jobs.admin_id and import_logs.admin_id were changed
        // from CASCADE to ON DELETE RESTRICT (BASELINE v1.2 §4.3.2). Deleting an
        // admin with import history must be blocked.
        $this->setUpOrg();

        $admin = $this->actAsAdmin();
        ImportJob::create([
            'import_type' => 'student_enrollment',
            'admin_id' => $admin->id,
            'total_rows' => 1,
            'imported_rows' => 0,
            'failed_rows' => 1]);

        try {
            DB::transaction(function () use ($admin): void {
                $admin->delete();
            });
            $this->fail('Expected QueryException: deleting an admin with import jobs must RESTRICT (ARCH-004 §4.1).');
        } catch (QueryException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
        }

        // Both the admin and its import history survive the rejected delete.
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('import_jobs', ['admin_id' => $admin->id]);
    }

    public function test_learning_material_without_subject(): void
    {
        // ARCH-004 §4.1: learning_materials uses subject_id (classroom scope) and a
        // title column was added (REQ-DEC-013, ARCH-004 §4.1). A material without
        // a subject is legal.
        $this->setUpOrg();

        $material = LearningMaterial::create([
            'teacher_id' => $this->teacher->id,
            'subject_id' => null,
            'competency_id' => $this->competencyTag1->id,
            'title' => 'Rational Numbers Study Guide',
            'filename' => 'study-guide.pdf',
            'original_filename' => 'Rational Numbers Study Guide.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024]);

        $this->assertNotNull($material->id);
        $this->assertDatabaseHas('learning_materials', [
            'id' => $material->id,
            'title' => 'Rational Numbers Study Guide']);
    }

    public function test_audit_enum_has_error_report_download(): void
    {
        // ARCH-004 §4.1: the audit_event_type native enum holds the 11 specified
        // literals including error_report_download, with 'export' renamed
        // (ARCH-004 §4.1, ARCH-004 §4.1 verification).
        $rows = DB::select(
            "SELECT e.enumlabel
             FROM pg_enum e
             JOIN pg_type t ON t.oid = e.enumtypid
             WHERE t.typname = 'audit_event_type'
             ORDER BY e.enumsortorder"
        );
        $labels = array_map(static fn ($row) => $row->enumlabel, $rows);

        $this->assertCount(11, $labels);
        $this->assertContains('error_report_download', $labels);
        $this->assertNotContains('export', $labels);
    }
}
