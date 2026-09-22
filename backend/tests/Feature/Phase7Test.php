<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AIExplanation;
use App\Models\Assessment;
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
use App\Services\AIService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

#[Group('phase7')]
class Phase7Test extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;
    private ?User $student = null;
    private ?User $admin = null;
        private ?Section $section = null;
    private ?Subject $subject = null;
    private ?Classroom $classroom = null;
    private ?CompetencyReference $competencyTag1 = null;
    private ?CompetencyReference $competencyTag2 = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = null;
        $this->student = null;
        $this->admin = null;
        $this->section = null;
        $this->classroom = null;
        $this->competencyTag1 = null;
        $this->competencyTag2 = null;

        // ARCH-006 §6: AI generation is mock-gated in tests — no OpenRouter wire.
        config()->set('services.openrouter.mock', true);
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
                'must_change_password' => false,
                'name' => 'Alice Student']);
        }

        Sanctum::actingAs($this->student);

        return $this->student;
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
     * Build a full org hierarchy with TWO competency tags for testing.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P7']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P7']);
        $this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P7']);

        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GE-002',
            'descriptor' => 'Classify geometric figures',
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
     * Create a released assessment with the given items.
     */
    private function createReleasedAssessment(string $type, array $items): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Phase 7 ' . $type . ' Test',
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
     * Student starts and submits an assessment, then teacher releases results.
     */
    private function studentStartAndSubmitAndRelease(int $assessmentId, array $responses): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/start");

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

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/release-results");
    }

    /**
     * Generate an AI explanation for the student's first incorrect item in a competency.
     */
    private function generateExplanation(int $studentId, int $assessmentId, int $competencyId): AIExplanation
    {
        $aiService = $this->app->make(AIService::class);
        $aiService->generateSingleExplanation($studentId, $assessmentId, $competencyId);

        return AIExplanation::where('student_id', $studentId)
            ->where('assessment_id', $assessmentId)
            ->where('is_follow_up', false)
            ->first();
    }

    // ========================================================================
    // #95 — AI Status
    // ========================================================================

    public function test_95_ai_status_returns_200_when_available(): void
    {
        $this->actAsStudent();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/ai/status');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'available');
    }

    // ========================================================================
    // #83–#86 — Learning Materials
    // ========================================================================

    public function test_84_learning_material_store_success(): void
    {
        $this->setUpOrg();

        $file = File::fake()->create('guide.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Fraction Guide',
                'file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', null); // title maps to original_filename, not a separate column
        $this->assertNotNull($response->json('data.id'));
        $this->assertNotNull($response->json('data.original_filename'));
        $this->assertEquals('Fraction Guide', $response->json('data.original_filename'));

        $this->assertDatabaseHas('learning_materials', [
            'teacher_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'competency_id' => $this->competencyTag1->id,
            'original_filename' => 'Fraction Guide']);
    }

    public function test_84_learning_material_store_requires_subject_id(): void
    {
        $this->setUpOrg();

        $file = File::fake()->create('guide.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Fraction Guide',
                'file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_84_learning_material_store_requires_competency_id(): void
    {
        $this->setUpOrg();

        $file = File::fake()->create('guide.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'title' => 'Fraction Guide',
                'file' => $file]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_84_learning_material_store_requires_file(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Fraction Guide']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_84_learning_material_store_rejects_unassigned_section(): void
    {
        $this->setUpOrg();

        $otherSection = Section::create([
            'grade_level_id' => $this->section->grade_level_id,
            'name' => '7B-P7']);
        $otherSubject = Subject::create(['grade_level_id' => $this->section->grade_level_id, 'name' => 'Science', 'code' => 'SCI7-P7']);

        $file = File::fake()->create('guide.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $otherSubject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Fraction Guide',
                'file' => $file]);

        $response->assertStatus(403);
    }

    public function test_83_learning_materials_index_for_teacher_section(): void
    {
        $this->setUpOrg();

        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Guide 1',
                'file' => File::fake()->create('g1.pdf', 100, 'application/pdf')]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/learning-materials?subject_id=' . $this->subject->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    // ========================================================================
    // #90–#94 — Moderation Log
    // ========================================================================

    public function test_90_moderation_log_list_returns_serialized_shape(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 1/2 + 1/2?',
                'max_points' => 10,
                'correct_answer' => '1',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Student answers incorrectly so an explanation is generated.
        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        // Generate the explanation.
        $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/moderation-log');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertIsArray($data[0]);

        // Verify serialized shape includes student_name and school_id.
        $this->assertArrayHasKey('student_name', $data[0]);
        $this->assertEquals('Alice Student', $data[0]['student_name']);

        $this->assertArrayHasKey('student_school_id', $data[0]);
        $this->assertEquals($this->student->school_id, $data[0]['student_school_id']);

        $this->assertArrayHasKey('school_id', $data[0]);
        $this->assertEquals($this->student->school_id, $data[0]['school_id']);

        $this->assertArrayHasKey('explanation_text', $data[0]);
        $this->assertArrayHasKey('flagged', $data[0]);
        $this->assertArrayHasKey('teacher_note', $data[0]);
        $this->assertArrayHasKey('explain_further_disabled', $data[0]);
        $this->assertArrayHasKey('competency_code', $data[0]);
        $this->assertArrayHasKey('assessment_title', $data[0]);
    }

    public function test_91_moderation_log_show_includes_school_id(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 1/2 + 1/2?',
                'max_points' => 10,
                'correct_answer' => '1',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/teacher/moderation-log/{$explanation->id}");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('school_id', $data);
        $this->assertEquals($this->student->school_id, $data['school_id']);

        $this->assertArrayHasKey('student_name', $data);
        $this->assertEquals('Alice Student', $data['student_name']);

        $this->assertArrayHasKey('student_id', $data);
        $this->assertArrayHasKey('assessment_id', $data);
        $this->assertArrayHasKey('explanation_text', $data);
        $this->assertArrayHasKey('flagged', $data);
    }

    public function test_90_moderation_log_scoped_to_teacher_sections(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/moderation-log');

        $response->assertOk();
        // The teacher should see the explanation.
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_90_moderation_log_unassigned_section_returns_403(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/teacher/moderation-log?section_id=99999');

        // Unknown sections fail the teacher-ownership check first.
        $response->assertForbidden();
    }

    public function test_92_flag_explanation_sets_flagged_and_note(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/moderation-log/{$explanation->id}/flag", [
                'note' => 'Inappropriate content']);

        $response->assertOk();
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'flagged' => true,
            'teacher_note' => 'Inappropriate content']);
    }

    public function test_93_append_teacher_note(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/moderation-log/{$explanation->id}/note", [
                'note' => 'Reviewed by teacher.']);

        $response->assertOk();
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'teacher_note' => 'Reviewed by teacher.']);
    }

    public function test_93_append_teacher_note_requires_note(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/moderation-log/{$explanation->id}/note");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_94_disable_explain_further(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/moderation-log/{$explanation->id}/disable-explain-further");

        $response->assertOk();
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'explain_further_disabled' => true]);
    }

    // ========================================================================
    // #89 — Explain Further (error codes)
    // ========================================================================

    public function test_89_explain_further_subjective_item_returns_422_with_code(): void
    {
        $this->setUpOrg();

        // Create an assessment with a subjective (essay) item.
        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'essay',
                'prompt' => 'Explain the concept of fractions.',
                'max_points' => 10,
                'correct_answer' => null,
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['My answer']);

        // Manually create an AIExplanation for this subjective item.
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();
        $explanation = AIExplanation::create([
            'student_id' => $this->student->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => AssessmentSubmission::where('assessment_id', $assessment->id)->first()->id,
            'item_id' => $item->id,
            'explanation_text' => 'Sample explanation.',
            'is_ungrounded' => false,
            'is_follow_up' => false,
            'turn_number' => 0,
            'flagged' => false,
            'explain_further_disabled' => false]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EXPLAIN_FURTHER_SUBJECTIVE_ITEM');
    }

    public function test_89_explain_further_turn_limit_returns_422_with_code(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();
        $explanation = AIExplanation::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'assessment_id' => $assessment->id,
                'item_id' => $item->id,
                'is_follow_up' => false,
                'turn_number' => 0],
            [
                'assessment_submission_id' => AssessmentSubmission::where('assessment_id', $assessment->id)->first()->id,
                'explanation_text' => 'Original explanation.',
                'is_ungrounded' => false,
                'parent_explanation_id' => null,
                'flagged' => false,
                'explain_further_disabled' => false]
        );

        // Create 2 follow-up turns (the maximum).
        AIExplanation::create([
            'student_id' => $this->student->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $explanation->assessment_submission_id,
            'item_id' => $item->id,
            'explanation_text' => 'Follow-up 1.',
            'is_ungrounded' => false,
            'is_follow_up' => true,
            'parent_explanation_id' => $explanation->id,
            'turn_number' => 1,
            'flagged' => false,
            'explain_further_disabled' => false]);
        AIExplanation::create([
            'student_id' => $this->student->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $explanation->assessment_submission_id,
            'item_id' => $item->id,
            'explanation_text' => 'Follow-up 2.',
            'is_ungrounded' => false,
            'is_follow_up' => true,
            'parent_explanation_id' => $explanation->id,
            'turn_number' => 2,
            'flagged' => false,
            'explain_further_disabled' => false]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EXPLAIN_FURTHER_TURN_LIMIT');
    }

    public function test_89_explain_further_disabled_returns_422_with_code(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();
        $explanation = AIExplanation::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'assessment_id' => $assessment->id,
                'item_id' => $item->id,
                'is_follow_up' => false,
                'turn_number' => 0],
            [
                'assessment_submission_id' => AssessmentSubmission::where('assessment_id', $assessment->id)->first()->id,
                'explanation_text' => 'Original explanation.',
                'is_ungrounded' => false,
                'parent_explanation_id' => null,
                'flagged' => false,
                'explain_further_disabled' => false]
        );
        $explanation->explain_further_disabled = true;
        $explanation->disabled_by_teacher_id = $this->teacher->id;
        $explanation->save();

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'EXPLAIN_FURTHER_DISABLED');
    }

    public function test_89_explain_further_success_returns_200(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();
        $explanation = AIExplanation::firstOrCreate(
            [
                'student_id' => $this->student->id,
                'assessment_id' => $assessment->id,
                'item_id' => $item->id,
                'is_follow_up' => false,
                'turn_number' => 0],
            [
                'assessment_submission_id' => AssessmentSubmission::where('assessment_id', $assessment->id)->first()->id,
                'explanation_text' => 'Original explanation.',
                'is_ungrounded' => false,
                'parent_explanation_id' => null,
                'flagged' => false,
                'explain_further_disabled' => false]
        );

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertOk();
        $response->assertJsonPath('data.success', true);
        $response->assertJsonPath('data.is_follow_up', true);
        $response->assertJsonPath('data.turn_number', 1);
    }

    public function test_89_explain_further_not_found_404(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->post('/api/student/explanations/99999/explain-further', [
                'item_id' => 1]);

        $response->assertNotFound();
    }

    // ========================================================================
    // AI Service Outage (#89 AI failure → 503)
    // ========================================================================

    public function test_89_explain_further_ai_outage_returns_503(): void
    {
        $this->setUpOrg();

        // Set a fake API key and fake OpenRouter to return 500.
        config()->set('services.openrouter.mock', false);
        config()->set('services.openrouter.api_key', 'fake-key-for-testing');
        Http::fake([
            'openrouter.ai/*' => Http::response([], 500)]);

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();
        $explanation = AIExplanation::create([
            'student_id' => $this->student->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => AssessmentSubmission::where('assessment_id', $assessment->id)->first()->id,
            'item_id' => $item->id,
            'explanation_text' => 'Original explanation.',
            'is_ungrounded' => false,
            'is_follow_up' => false,
            'turn_number' => 0,
            'flagged' => false,
            'explain_further_disabled' => false]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->post("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');
    }

    // ========================================================================
    // #87–#88 — Student Explanations
    // ========================================================================

    public function test_87_list_explanations_after_release(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        // Generate explanation (mock boundary: no API key → MOCK_EXPLANATION).
        $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('explanation_text', $data[0]);
        $this->assertArrayHasKey('competency_id', $data[0]);
        $this->assertArrayHasKey('competency_code', $data[0]);
    }

    public function test_88_show_explanation_returns_detail(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->student, 'sanctum')
            ->get("/api/student/explanations/{$explanation->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($explanation->id, $data['id']);
        $this->assertArrayHasKey('explanation_text', $data);
        $this->assertArrayHasKey('is_ungrounded', $data);
        $this->assertArrayHasKey('explain_further_available', $data);
        $this->assertArrayHasKey('has_disclaimers', $data);
    }

    // ========================================================================
    // getIncorrectlyAnsweredItems filter verification
    // ========================================================================

    public function test_generate_explanation_only_for_incorrect_items(): void
    {
        $this->setUpOrg();

        // 3 items: 2 correct, 1 incorrect — all same competency.
        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Correct Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Correct Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Incorrect Q3',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Student answers: Q1=correct(A), Q2=correct(B), Q3=wrong(X).
        $this->studentStartAndSubmitAndRelease($assessment->id, ['A', 'B', 'X']);

        // Generate explanation for this competency.
        $aiService = $this->app->make(AIService::class);
        $result = $aiService->generateSingleExplanation(
            $this->student->id,
            $assessment->id,
            $this->competencyTag1->id
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['explanation']);

        // Verify that exactly one explanation was stored.
        $explanations = AIExplanation::where('student_id', $this->student->id)
            ->where('assessment_id', $assessment->id)
            ->where('is_follow_up', false)
            ->get();

        $this->assertCount(1, $explanations);

        // The stored explanation should be for the incorrect item (Q3).
        $incorrectItem = AssessmentItem::where('assessment_id', $assessment->id)
            ->where('prompt', 'Incorrect Q3')
            ->first();
        $this->assertEquals($incorrectItem->id, $explanations->first()->item_id);
    }

    // ========================================================================
    // Access control
    // ========================================================================

    public function test_90_moderation_log_requires_teacher_role(): void
    {
        $this->setUpOrg();

        $this->actAsStudent();

        $response = $this->actingAs($this->student, 'sanctum')
            ->get('/api/teacher/moderation-log');

        $response->assertStatus(403);
    }

    public function test_87_list_explanations_requires_student_role(): void
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get('/api/student/assessments/1/explanations');

        $response->assertStatus(403);
    }

    public function test_95_ai_status_requires_authentication(): void
    {
        $response = $this->get('/api/ai/status');

        $response->assertStatus(401);
    }

    // ========================================================================
    // Unrecorded excluded from moderation (ARCH-002 FR-021/ARCH-002 FR-021)
    // ========================================================================

    public function test_unrecorded_assessment_explanations_not_in_mastery_records(): void
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Unrecorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        // An AIExplanation can still be generated for Unrecorded assessments (ARCH-002 FR-027).
        $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        // But no mastery record should exist (ARCH-002 FR-021, ARCH-002 FR-021).
        $this->assertDatabaseMissing('mastery_records', [
            'student_id' => $this->student->id,
            'competency_id' => $this->competencyTag1->id]);
    }
}
