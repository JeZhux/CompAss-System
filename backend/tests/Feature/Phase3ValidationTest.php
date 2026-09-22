<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AssessmentItem;
use App\Models\Classroom;
use App\Models\ClassroomEnrollment;
use App\Models\CompetencyReference;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Semester;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

#[Group('phase3-validation')]
class Phase3ValidationTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;

    private ?User $student = null;

    private ?Subject $subject = null;

    private ?Classroom $classroom = null;

    private ?CompetencyReference $competencyTag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->student = null;
        $this->subject = null;
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
                'must_change_password' => false.fake()->unique()->numberBetween(10000, 99999)]);
        }

        Sanctum::actingAs($this->student);

        return $this->student;
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
        $section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7']);
                $this->competencyTag = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $teacher = $this->actAsTeacher();

        $student = $this->actAsStudent();
        $this->classroom = app(ClassroomService::class)->createClassroom($teacher->id, $this->subject->id, $section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $student->id,
            'joined_at' => now()]);
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

    private function createAssessment(array $overrides = []): int
    {
        $defaults = [
            'title' => 'Test Assessment',
            'description' => '',
            'type' => 'Recorded'];

        $assessment = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', array_merge($defaults, $overrides))
            ->json('data');

        return (int) $assessment['id'];
    }

    private function createAndReleaseAssessment(array $overrides = [], array $items = []): int
    {
        $assessmentId = $this->createAssessment($overrides);

        foreach ($items as $itemData) {
            $this->actingAs($this->teacher, 'sanctum')
                ->post("/api/teacher/assessments/{$assessmentId}/items", $itemData)
                ->json('data');
        }

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/release");

        return $assessmentId;
    }

    private function firstItemId(int $assessmentId): int
    {
        return (int) AssessmentItem::where('assessment_id', $assessmentId)->value('id');
    }

    // Placeholder for edit
    public function test_store_announcement_requires_classroom_scope(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/announcements', [
                'title' => 'Test',
                'body' => 'Body']);

        $this->assertValidationError($response, 'classroom_id');
    }

    public function test_store_announcement_mismatched_subject_returns_422(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/announcements', [
                'subject_id' => 999999,
                'title' => 'Test',
                'body' => 'Body']);

        $this->assertValidationError($response, 'subject_id');
    }

    public function test_store_announcement_missing_title(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/announcements', [
                'subject_id' => $this->subject->id,
                'body' => 'Body']);

        $this->assertValidationError($response, 'title');
    }

    public function test_store_announcement_missing_body(): void
    {
        Storage::fake('local');
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/announcements', [
                'subject_id' => $this->subject->id,
                'title' => 'Test']);

        $this->assertValidationError($response, 'body');
    }

    // ========================================================================
    // Assignment validation (#41, #43, #48)
    // ========================================================================

    public function test_store_assignment_requires_classroom_scope(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assignments', [
                'title' => 'Assignment',
                'description' => 'Desc',
                'due_date' => '2026-12-31 23:59:00']);

        $this->assertValidationError($response, 'classroom_id');
    }

    public function test_store_assignment_mismatched_subject_returns_422(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assignments', [
                'subject_id' => 999999,
                'title' => 'Assignment',
                'description' => 'Desc',
                'due_date' => '2026-12-31 23:59:00']);

        $this->assertValidationError($response, 'subject_id');
    }

    public function test_store_assignment_missing_title(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assignments', [
                'subject_id' => $this->subject->id,
                'description' => 'Desc',
                'due_date' => '2026-12-31 23:59:00']);

        $this->assertValidationError($response, 'title');
    }

    public function test_store_assignment_missing_due_date(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Assignment',
                'description' => 'Desc']);

        $this->assertValidationError($response, 'due_date');
    }

    public function test_store_assignment_invalid_due_date(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assignments', [
                'subject_id' => $this->subject->id,
                'title' => 'Assignment',
                'description' => 'Desc',
                'due_date' => 'not-a-date']);

        $this->assertValidationError($response, 'due_date');
    }

    // ========================================================================
    // Assessment validation (#55, #56, #58, #59)
    // ========================================================================

    public function test_store_assessment_requires_classroom_scope(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assessments', [
                'title' => 'Test',
                'type' => 'Recorded']);

        $this->assertValidationError($response, 'classroom_id');
    }

    public function test_store_assessment_mismatched_subject_returns_422(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/classrooms/'.$this->classroom->id.'/assessments', [
                'subject_id' => 999999,
                'title' => 'Test',
                'type' => 'Recorded']);

        $this->assertValidationError($response, 'subject_id');
    }

    public function test_store_assessment_missing_title(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assessments', [
                'subject_id' => $this->subject->id,
                'type' => 'Recorded']);

        $this->assertValidationError($response, 'title');
    }

    public function test_store_assessment_missing_type(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Test']);

        $this->assertValidationError($response, 'type');
    }

    public function test_store_assessment_invalid_type(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Test',
                'type' => 'InvalidType']);

        $this->assertValidationError($response, 'type');
    }

    public function test_store_assessment_invalid_time_limit(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Test',
                'type' => 'Recorded',
                'time_limit' => 0]);

        $this->assertValidationError($response, 'time_limit');
    }

    public function test_store_assessment_availability_ends_before_starts(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/assessments', [
                'subject_id' => $this->subject->id,
                'title' => 'Test',
                'type' => 'Recorded',
                'availability_starts_at' => '2026-12-31 10:00:00',
                'availability_ends_at' => '2026-12-01 10:00:00']);

        $this->assertValidationError($response, 'availability_ends_at');
    }

    // ========================================================================
    // Assessment Item validation (#58, #59)
    // ========================================================================

    public function test_store_assessment_item_missing_item_type(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'item_type');
    }

    public function test_store_assessment_item_invalid_item_type(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'invalid_type',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'item_type');
    }

    public function test_store_assessment_item_missing_prompt(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'prompt');
    }

    public function test_store_assessment_item_missing_max_points(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'max_points');
    }

    public function test_store_assessment_item_max_points_below_minimum(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 0,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'max_points');
    }

    public function test_store_assessment_item_missing_correct_answer_for_objective(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'correct_answer');
    }

    public function test_store_assessment_item_nonexistent_competency_tag(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => 999999,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'competency_tag_id');
    }

    public function test_store_assessment_item_negative_sort_order(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => -1]);

        $this->assertValidationError($response, 'sort_order');
    }

    public function test_update_assessment_item_invalid_item_type(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$item['id']}", [
                'item_type' => 'invalid_type']);

        $this->assertValidationError($response, 'item_type');
    }

    public function test_update_assessment_item_max_points_below_minimum(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$item['id']}", [
                'max_points' => 0]);

        $this->assertValidationError($response, 'max_points');
    }

    public function test_update_assessment_item_nonexistent_competency_tag(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$item['id']}", [
                'competency_tag_id' => 999999]);

        $this->assertValidationError($response, 'competency_tag_id');
    }

    // ========================================================================
    // Student validation (#64, #69, #70, #71)
    // ========================================================================

    public function test_auto_save_missing_responses(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", []);

        $this->assertValidationError($response, 'responses');
    }

    public function test_auto_save_non_array_responses(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->put("/api/student/assessments/{$assessmentId}/auto-save", [
                'responses' => 'not-an-array']);

        $this->assertValidationError($response, 'responses');
    }

    public function test_patch_response_missing_question_id(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'response' => 'A']);

        $this->assertValidationError($response, 'questionId');
    }

    public function test_patch_response_non_integer_question_id(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => 'abc',
                'response' => 'A']);

        $this->assertValidationError($response, 'questionId');
    }

    public function test_patch_response_question_id_below_minimum(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => 0,
                'response' => 'A']);

        $this->assertValidationError($response, 'questionId');
    }

    public function test_patch_response_missing_response_text(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId]);

        $this->assertValidationError($response, 'response');
    }

    public function test_patch_response_too_long(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);
        $itemId = $this->firstItemId($assessmentId);

        $startData = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start")
            ->json('data');

        $attemptId = $startData['attempt_id'];

        $response = $this->actingAs($this->student, 'sanctum')
            ->patch("/api/student/assessments/{$assessmentId}/attempts/{$attemptId}/response", [
                'questionId' => $itemId,
                'response' => str_repeat('a', 10001)]);

        $this->assertValidationError($response, 'response');
    }

    public function test_score_subjective_items_missing_scores(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/submissions/999999/score', []);

        $this->assertValidationError($response, 'scores');
    }

    public function test_score_subjective_items_non_array_scores(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/submissions/999999/score', [
                'scores' => 'not-an-array']);

        $this->assertValidationError($response, 'scores');
    }

    public function test_score_subjective_items_negative_scores(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/submissions/999999/score', [
                'scores' => [-5]]);

        $this->assertValidationError($response, 'scores.0');
    }

    public function test_submit_assessment_non_array_responses(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => 'not-an-array']);

        $this->assertValidationError($response, 'responses');
    }

    public function test_submit_assessment_non_string_response_values(): void
    {
        $this->setUpOrg();
        $this->actAsStudent();

        $assessmentId = $this->createAndReleaseAssessment([], [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]]);
        $itemId = $this->firstItemId($assessmentId);

        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => [$itemId => 12345]]);

        $this->assertValidationError($response, 'responses.'.$itemId);
    }

    // ========================================================================
    // WU-2: item authoring validation (ARCH-002 FR-015)
    // ========================================================================

    public function test_add_item_rejects_non_positive_max_points(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => -5,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1]);

        $this->assertValidationError($response, 'max_points');
    }

    public function test_add_item_rejects_infinite_max_points(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        // INF cannot traverse JSON — the service boundary is the only place
        // the finite check can fire, so it is exercised service-level.
        $service = app(AssessmentService::class);

        try {
            $service->addItem(
                $this->teacher->id,
                $assessmentId,
                'multiple_choice',
                'What is 2+2?',
                INF,
                'A',
                $this->competencyTag->id
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('max_points', $e->errors());
            $this->assertDatabaseMissing('assessment_items', ['assessment_id' => $assessmentId]);
        }
    }

    public function test_update_item_rejects_non_finite_max_points(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])->json('data');

        $service = app(AssessmentService::class);

        try {
            $service->updateItem($this->teacher->id, $item['id'], null, null, NAN, null, null);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('max_points', $e->errors());
            $this->assertDatabaseHas('assessment_items', [
                'id' => $item['id'],
                'max_points' => 10]);
        }
    }

    public function test_update_item_rejects_invalid_type(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $item = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])->json('data');

        // HTTP is gated by the FormRequest allow-list; this exercises the
        // service boundary (ARCH-002 FR-015 updateItem allow-list).
        $service = app(AssessmentService::class);

        try {
            $service->updateItem($this->teacher->id, $item['id'], 'invalid_type', null, null, null, null);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('item_type', $e->errors());
        }
    }

    public function test_update_item_type_switch_revalidates_correct_answer(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $essayItem = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'essay',
                'prompt' => 'Write an essay here',
                'max_points' => 10,
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])->json('data');

        // ARCH-002 FR-015: essay → objective without a correct_answer → 422.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$essayItem['id']}", [
                'item_type' => 'multiple_choice']);

        $this->assertValidationError($response, 'correct_answer');

        $objectiveItem = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 2])->json('data');

        // ARCH-002 FR-015: objective → essay while carrying a correct_answer → 422.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$objectiveItem['id']}", [
                'item_type' => 'essay',
                'correct_answer' => 'A']);

        $this->assertValidationError($response, 'correct_answer');
    }

    public function test_update_item_type_switch_to_essay_clears_correct_answer(): void
    {
        $this->setUpOrg();
        $assessmentId = $this->createAssessment();

        $objectiveItem = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/items", [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 2+2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag->id,
                'sort_order' => 1])->json('data');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->put("/api/teacher/items/{$objectiveItem['id']}", [
                'item_type' => 'essay']);

        $response->assertOk();

        // ARCH-002 FR-015: an essay item must end up with a NULL correct_answer.
        $this->assertDatabaseHas('assessment_items', [
            'id' => $objectiveItem['id'],
            'item_type' => 'essay',
            'correct_answer' => null]);
    }
}
