<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAutoSave;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

#[Group('phase3-student-assessment-flow')]
class Phase3StudentAssessmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    private ?User $studentB = null;

    
    private ?Subject $subject = null;

    private ?Section $section = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->student = null;
        $this->studentB = null;
        $this->section = null;
        $this->classroom = null;
        $this->competencyTag = null;
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

    private function actAsStudentB(): User
    {
        if (! $this->studentB) {
            $this->studentB = User::factory()->create([
                'role' => 'Student',
                'must_change_password' => false]);
        }

        Sanctum::actingAs($this->studentB);

        return $this->studentB;
    }

    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
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

    private function createReleasedAssessmentWithItems(array $overrides = [], array $items = []): int
    {
        $defaults = [
            'title' => 'Test Assessment',
            'description' => '',
            'type' => 'Recorded'];

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', array_merge($defaults, $overrides))
            ->json('data');

        foreach ($items as $itemData) {
            $this->actingAs($this->teacher, 'sanctum')
                ->post("/api/teacher/assessments/{$assessment['id']}/items", $itemData)
                ->json('data');
        }

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment['id']}/release");

        return (int) $assessment['id'];
    }

    private function firstItemId(int $assessmentId): int
    {
        return (int) AssessmentItem::where('assessment_id', $assessmentId)->value('id');
    }

    private function basicItem(): array
    {
        return [
            'item_type' => 'multiple_choice',
            'prompt' => 'What is 2+2?',
            'max_points' => 10,
            'correct_answer' => 'B',
            'competency_tag_id' => $this->competencyTag->id,
            'sort_order' => 1];
    }

    // ========================================================================
    // Pre-start view (#67)
    // ========================================================================

    public function test_student_can_view_assessment_before_starting(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/assessments/{$assessmentId}");

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Test Assessment');
        $response->assertJsonPath('data.item_count', 1);
        $response->assertJsonMissingPath('data.items');
    }

    public function test_student_can_list_available_assessments(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/student/assessments');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $found = collect($data)->first(fn ($a) => $a['id'] === $assessmentId);
        $this->assertNotNull($found);
        $this->assertEquals('available', $found['status']);
        $this->assertEquals(1, $found['item_count']);
    }

    public function test_unenrolled_student_cannot_view_assessment_pre_start(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $this->actAsStudentB();
        // studentB is not enrolled in the classroom
        $response = $this->actingAs($this->studentB, 'sanctum')
            ->get("/api/student/assessments/{$assessmentId}");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'NOT_ENROLLED');
    }

    // ========================================================================
    // Auto-save negative paths (#69)
    // ========================================================================

    public function test_auto_save_404_for_nonexistent_assessment(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $response = $this->actingAs($this->student, 'sanctum')
            ->put('/api/student/assessments/999999/auto-save', [
                'responses' => ['1' => 'A']]);

        $response->assertStatus(404);
    }

    public function test_auto_save_404_after_submission(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'C']]);

        $response->assertStatus(404);
    }

    // ========================================================================
    // Patch response negative paths (#70)
    // ========================================================================

    public function test_patch_response_404_for_nonexistent_attempt(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/999999/response", [
                'questionId' => $itemId,
                'response' => 'A']);

        $response->assertStatus(404);
    }

    public function test_patch_response_404_for_another_student_attempt(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $this->actAsStudentB();
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->studentB->id,
            'joined_at' => now()]);

        $response = $this->actingAs($this->studentB, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'B']);

        $response->assertStatus(404);
    }

    public function test_patch_response_409_after_submission(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'C']);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ALREADY_SUBMITTED');
    }

    // ========================================================================
    // Auto-save cleanup on submit
    // ========================================================================

    public function test_auto_save_cleaned_up_after_objective_submit(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'B']]);

        $this->assertDatabaseHas('assessment_auto_saves', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']]);

        $this->assertDatabaseMissing('assessment_auto_saves', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId]);
    }

    public function test_auto_save_cleaned_up_after_essay_submit(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $items = [
            [
                'item_type' => 'essay',
                'prompt' => 'Write an essay now please',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]];
        $assessmentId = $this->createReleasedAssessmentWithItems([], $items);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'My essay text']]);

        $this->assertDatabaseHas('assessment_auto_saves', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'My essay text']]);

        $this->assertDatabaseMissing('assessment_auto_saves', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId]);
    }

    // ========================================================================
    // Response history via patch endpoint (#70)
    // ========================================================================

    public function test_patch_response_creates_history_entry(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'A'])->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'B'])->assertOk();

        $attempt = AssessmentAttempt::find($attemptId);
        $history = $attempt->response_history;

        $this->assertIsArray($history);
        $this->assertCount(2, $history);
        $this->assertEquals('A', $history[0]['new_value']);
        $this->assertEquals('B', $history[1]['new_value']);
        $this->assertNull($history[0]['priorValue']);
        $this->assertEquals('A', $history[1]['priorValue']);
    }

    public function test_patch_response_updates_existing_history_entry(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'first'])->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'second'])->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'third'])->assertOk();

        $attempt = AssessmentAttempt::find($attemptId);
        $history = $attempt->response_history;

        $this->assertCount(3, $history);
        $this->assertEquals('third', $history[2]['new_value']);
        $this->assertEquals('second', $history[2]['priorValue']);
    }

    // ========================================================================
    // Auto-save resume (#69)
    // ========================================================================

    public function test_auto_save_resume_second_start_rejected(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'B']]);

        // ARCH-002 FR-017: the one-active-attempt guard replaces start-to-resume;
        // the client keeps the attempt_id from the first start.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'STUDENT_HAS_ACTIVE_ATTEMPT');
    }

    public function test_auto_save_upsert_replaces_previous(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'A']])->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'B']])->assertOk();

        $autoSave = AssessmentAutoSave::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $assessmentId)
            ->first();

        $this->assertNotNull($autoSave);
        $this->assertIsArray($autoSave->responses);
        $this->assertCount(1, $autoSave->responses);
    }

    // ========================================================================
    // Submit flow (#72)
    // ========================================================================

    // ========================================================================
    // Submit flow (#71)
    // ========================================================================

    public function test_submit_without_starting_returns_404(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']]);

        $response->assertStatus(404);
    }

    public function test_objective_submit_returns_scored_status(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');
        $response->assertJsonPath('data.mastery_results.status', 'computed');
    }

    public function test_essay_submit_returns_pending_grading_status(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $items = [
            [
                'item_type' => 'essay',
                'prompt' => 'Write an essay now please',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]];
        $assessmentId = $this->createReleasedAssessmentWithItems([], $items);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'My essay text']]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');
    }

    public function test_submit_with_empty_responses_still_creates_responses(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", []);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');

        $this->assertDatabaseHas('assessment_responses', [
            'item_id' => $itemId ?? $this->firstItemId($assessmentId),
            'response_text' => null,
            'earned_points' => 0]);
    }

    // ========================================================================
    // WU-1 schema half: attempt lifecycle (ARCH-004 §4.1/057/064)
    // ========================================================================

    public function test_migrate_fresh_clean(): void
    {
        $this->assertTrue(Schema::hasColumn('assessment_attempts', 'started_at'));
        $this->assertTrue(Schema::hasColumn('assessment_attempts', 'is_resubmission'));
        $this->assertTrue(Schema::hasColumn('assessment_attempts', 'resubmission_of_attempt_id'));
        $this->assertTrue(Schema::hasColumn('assessment_attempts', 'resubmission_reason'));
        $this->assertTrue(Schema::hasColumn('assessment_attempts', 'resubmission_requested_at'));
        $this->assertTrue(Schema::hasColumn('assessment_auto_saves', 'attempt_id'));
    }

    public function test_attempt_persists_started_at(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $attempt = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
            'started_at' => '2026-08-16 09:00:00']);

        $fresh = AssessmentAttempt::find($attempt->id);
        $this->assertNotNull($fresh->started_at);
        $this->assertInstanceOf(Carbon::class, $fresh->started_at);
        $this->assertEquals('2026-08-16 09:00:00', $fresh->started_at->format('Y-m-d H:i:s'));
    }

    public function test_attempt_resubmission_state_columns_persist(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $original = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'scored']);

        $resubmission = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $original->id,
            'resubmission_reason' => 'Teacher requested revision',
            'resubmission_requested_at' => '2026-08-16 10:00:00']);

        $fresh = AssessmentAttempt::find($resubmission->id);
        $this->assertTrue($fresh->is_resubmission);
        $this->assertEquals($original->id, $fresh->resubmission_of_attempt_id);
        $this->assertEquals('Teacher requested revision', $fresh->resubmission_reason);
        $this->assertInstanceOf(Carbon::class, $fresh->resubmission_requested_at);
        $this->assertNotNull($fresh->resubmissionOf);
        $this->assertEquals($original->id, $fresh->resubmissionOf->id);
    }

    public function test_auto_save_keyed_per_attempt(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $attemptA = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'in_progress']);
        $attemptB = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress']);

        AssessmentAutoSave::create([
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId,
            'attempt_id' => $attemptA->id,
            'responses' => ['1' => 'A']]);
        AssessmentAutoSave::create([
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId,
            'attempt_id' => $attemptB->id,
            'responses' => ['1' => 'B']]);

        $this->assertDatabaseHas('assessment_auto_saves', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId,
            'attempt_id' => $attemptA->id]);
        $this->assertDatabaseHas('assessment_auto_saves', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId,
            'attempt_id' => $attemptB->id]);
        $this->assertEquals(2, AssessmentAutoSave::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $assessmentId)
            ->count());
    }

    // ========================================================================
    // WU-2 service half: one-active-attempt guard, resubmission admission,
    // started_at, per-attempt autosave, time-limit (ARCH-004 §4.1/057/064)
    // ========================================================================

    private function secondItem(): array
    {
        return [
            'item_type' => 'true_false',
            'prompt' => 'Is 2+2=4?',
            'max_points' => 5,
            'correct_answer' => 'True',
            'competency_tag_id' => $this->competencyTag->id,
            'sort_order' => 2];
    }

    public function test_start_rejects_when_active_attempt_exists(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->assertOk();

        // ARCH-002 FR-017: a second start while in_progress must not orphan attempts.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'STUDENT_HAS_ACTIVE_ATTEMPT');

        $this->assertSame(1, AssessmentAttempt::query()
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $this->student->id)
            ->count());
    }

    public function test_start_rejects_existing_submission_without_resubmission_attempt(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->assertOk();

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']])
            ->assertOk();

        // ARCH-002 FR-018: no resubmission attempt exists → the dead end stays blocked.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ALREADY_SUBMITTED');
    }

    public function test_start_admits_resubmission_attempt(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $originalStart = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']])
            ->assertOk();

        // WU-3 (GradingService.requestResubmission) creates this attempt; here
        // it is created directly to exercise the admission gate in isolation.
        $resubmission = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $originalStart['attempt_id'],
            'resubmission_reason' => 'Teacher requested revision',
            'resubmission_requested_at' => now(),
            'response_history' => []]);

        // ARCH-002 FR-018: the start is admitted and returns the resubmission attempt
        // itself (no orphan is created).
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response->assertOk();
        $response->assertJsonPath('data.attempt_id', $resubmission->id);
        $response->assertJsonPath('data.attempt_number', 2);

        $this->assertSame(2, AssessmentAttempt::query()
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $this->student->id)
            ->count());
    }

    public function test_resubmission_start_prefills_previous_attempt_autosave(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $assessment = Assessment::findOrFail($assessmentId);
        $classroom = Classroom::findOrFail($assessment->classroom_id);

        $attemptOne = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 1,
            'status' => 'scored',
            'response_history' => []]);

        AssessmentSubmission::create([
            'attempt_id' => $attemptOne->id,
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'section_id' => $classroom->section_id,
            'semester_id' => $assessment->semester_id,
            'submitted_at' => now()->subDay(),
            'status' => 'scored']);

        AssessmentAutoSave::create([
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId,
            'attempt_id' => $attemptOne->id,
            'responses' => [$itemId => 'B']]);

        $resubmission = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $attemptOne->id,
            'resubmission_reason' => 'Teacher requested revision',
            'resubmission_requested_at' => now(),
            'response_history' => []]);

        // ARCH-002 FR-017: the resubmission start pre-fills from the previous
        // attempt's autosave row (the current attempt has none yet).
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response->assertOk();
        $this->assertEquals($resubmission->id, $response->json('data.attempt_id'));

        $responses = $response->json('data.responses');
        $this->assertEquals('B', $responses[(string) $itemId] ?? $responses[$itemId] ?? null);
    }

    public function test_start_persists_started_at(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        // ARCH-004 §4.1: started_at is persisted when the attempt starts.
        $attempt = AssessmentAttempt::find($startData['attempt_id']);
        $this->assertNotNull($attempt->started_at);
        $this->assertTrue($attempt->started_at->isToday());
    }

    public function test_resubmission_start_persists_started_at(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $originalStart = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 'B']])
            ->assertOk();

        $resubmission = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $originalStart['attempt_id'],
            'resubmission_reason' => 'Teacher requested revision',
            'resubmission_requested_at' => now(),
            'response_history' => []]);

        $this->assertNull($resubmission->fresh()->started_at);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->assertOk();

        // ARCH-004 §4.1: the student's start (not the teacher's request) sets it.
        $this->assertNotNull($resubmission->fresh()->started_at);
    }

    public function test_autosave_keyed_per_attempt_no_overwrite(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems([], [$this->basicItem()]);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'A']])
            ->assertOk();

        // Simulate the teacher-created resubmission attempt (WU-3).
        $secondAttempt = AssessmentAttempt::create([
            'assessment_id' => $assessmentId,
            'student_id' => $this->student->id,
            'attempt_number' => 2,
            'status' => 'in_progress',
            'is_resubmission' => true,
            'resubmission_of_attempt_id' => $startData['attempt_id'],
            'resubmission_requested_at' => now(),
            'response_history' => []]);

        $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'B']])
            ->assertOk();

        // ARCH-002 FR-017: two attempts' autosaves must never overwrite each other.
        $firstRow = AssessmentAutoSave::query()->where('attempt_id', $startData['attempt_id'])->first();
        $secondRow = AssessmentAutoSave::query()->where('attempt_id', $secondAttempt->id)->first();

        $this->assertNotNull($firstRow);
        $this->assertNotNull($secondRow);
        $this->assertEquals('A', $firstRow->responses[(string) $itemId] ?? $firstRow->responses[$itemId] ?? null);
        $this->assertEquals('B', $secondRow->responses[(string) $itemId] ?? $secondRow->responses[$itemId] ?? null);
    }

    public function test_submit_after_deadline_auto_submits_blanks_scored_zero(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems(
            ['time_limit' => 60],
            [$this->basicItem(), $this->secondItem()]
        );
        $itemOne = $this->firstItemId($assessmentId);
        $itemTwo = (int) AssessmentItem::query()
            ->where('assessment_id', $assessmentId)
            ->where('sort_order', 2)
            ->value('id');

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        // Backdate the start so the deadline has passed (ARCH-004 §4.1).
        AssessmentAttempt::where('id', $startData['attempt_id'])
            ->update(['started_at' => now()->subHours(2)]);

        // ARCH-002 FR-017: a late submit auto-submits; unanswered items are scored 0.
        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemOne => 'B']]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'scored');

        $attempt = AssessmentAttempt::find($startData['attempt_id']);
        $this->assertEquals('scored', $attempt->status);

        $submission = AssessmentSubmission::where('attempt_id', $startData['attempt_id'])->firstOrFail();
        $this->assertEquals('scored', $submission->status);

        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $submission->id,
            'item_id' => $itemOne,
            'response_text' => 'B',
            'earned_points' => 10]);
        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $submission->id,
            'item_id' => $itemTwo,
            'response_text' => null,
            'earned_points' => 0]);
        $this->assertDatabaseMissing('assessment_auto_saves', [
            'student_id' => $this->student->id,
            'assessment_id' => $assessmentId]);
    }

    public function test_submit_after_deadline_essay_auto_submits_blanks_scored_zero(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $items = [
            [
                'item_type' => 'essay',
                'prompt' => 'Write an essay now please',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]];
        $assessmentId = $this->createReleasedAssessmentWithItems(['time_limit' => 30], $items);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        AssessmentAttempt::where('id', $startData['attempt_id'])
            ->update(['started_at' => now()->subHours(2)]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", []);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'pending_grading');

        // Status transitions exactly like a normal submit: in_progress →
        // submitted → pending_grading when the assessment has essay items.
        $attempt = AssessmentAttempt::find($startData['attempt_id']);
        $this->assertEquals('pending_grading', $attempt->status);

        $submission = AssessmentSubmission::where('attempt_id', $startData['attempt_id'])->firstOrFail();
        $this->assertEquals('pending_grading', $submission->status);

        $this->assertDatabaseHas('assessment_responses', [
            'submission_id' => $submission->id,
            'item_id' => $itemId,
            'response_text' => null,
            'earned_points' => 0,
            'is_auto_scored' => true]);
    }

    public function test_autosave_after_deadline_rejected(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems(
            ['time_limit' => 60],
            [$this->basicItem()]
        );
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        AssessmentAttempt::where('id', $startData['attempt_id'])
            ->update(['started_at' => now()->subHours(2)]);

        // ARCH-004 §4.1: hard 409 for autosave after the deadline.
        $response = $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => [$itemId => 'C']]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TIME_LIMIT_EXPIRED');
    }

    public function test_patch_response_after_deadline_rejected(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createReleasedAssessmentWithItems(
            ['time_limit' => 60],
            [$this->basicItem()]
        );
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        AssessmentAttempt::where('id', $attemptId)
            ->update(['started_at' => now()->subHours(2)]);

        // ARCH-004 §4.1: hard 409 for response-patch after the deadline.
        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => 'C']);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TIME_LIMIT_EXPIRED');
    }
}
