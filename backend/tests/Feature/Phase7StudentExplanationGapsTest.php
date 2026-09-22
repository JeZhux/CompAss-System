<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use App\Models\AIExplanation;
use App\Models\Assessment;
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
use App\Services\AIService;
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 *
 * Closes the access-control, validation, ownership, follow-up-ordering, and
 * turn-accounting gaps the baseline Phase7Test does not assert for the Student
 * AI Explanation endpoints (#87–#89, API Interface Spec §3.9.2).
 *
 * Endpoints:
 *   #87 GET  /api/student/assessments/{id}/explanations
 *   #88 GET  /api/student/explanations/{id}
 *   #89 POST /api/student/explanations/{id}/explain-further
 *
 * Middleware: auth:sanctum → password.change.required → role:student.
 * Error envelope: { "error": { "message", "code" } } (ARCH-002 QA-007).
 */
#[Group('phase7-student-explanations')]
class Phase7StudentExplanationGapsTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;
    private ?User $student = null;
    private ?User $studentB = null;
    private ?User $passwordChangeStudent = null;
    private ?Subject $subject = null;
    private ?Section $section = null;
    private ?Classroom $classroom = null;
    private ?CompetencyReference $competencyTag1 = null;
    private ?CompetencyReference $competencyTag2 = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->student = $this->studentB = null;
        $this->passwordChangeStudent = null;
        $this->subject = $this->section = null;
        $this->classroom = null;
        $this->competencyTag1 = $this->competencyTag2 = null;

        // ARCH-006 §6: AI generation is mock-gated in tests — no OpenRouter wire.
        config()->set('services.openrouter.mock', true);
    }

    /**
     * Build a full org hierarchy with TWO competency tags and three students
     * (A, B, and a password-change-required student) for ownership tests.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P7G']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P7G']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P7G']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-01',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GEO-02',
            'descriptor' => 'Classify geometric figures',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $this->teacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $this->student = User::factory()->create([
            'role' => 'Student',
            'must_change_password' => false,
            'name' => 'Alice Student']);
        $this->studentB = User::factory()->create([
            'role' => 'Student',
            'must_change_password' => false,
            'name' => 'Bob Student']);
        $this->passwordChangeStudent = User::factory()->create([
            'role' => 'Student',
            'must_change_password' => true,
            'name' => 'Carol Student']);


        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);

        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'joined_at' => now()]);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->studentB->id,
            'joined_at' => now()]);
    }

    /**
     * Minimal single-page PDF with byte-accurate xref offsets, built at
     * runtime (upload-time extraction fixture — ARCH-002 FR-026/WU-7).
     */
    private function minimalPdf(string $text): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>'];
        $escaped = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);
        $stream = "BT\n/F1 12 Tf\n72 720 Td\n(" . $escaped . ") Tj\nET\n";
        $objects[4] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF";

        return $pdf;
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
            'title' => 'Phase 7 Gaps ' . $type . ' Test',
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
     * Student starts and submits an assessment (results NOT released).
     */
    private function studentStartAndSubmit(int $assessmentId, array $responses): void
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
    }

    /**
     * Student starts and submits an assessment, then teacher releases results.
     */
    private function studentStartAndSubmitAndRelease(int $assessmentId, array $responses): void
    {
        $this->studentStartAndSubmit($assessmentId, $responses);

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

    /**
     * Create a follow-up turn row for a primary explanation.
     */
    private function createFollowUp(
        AIExplanation $primary,
        int $turnNumber,
        string $text = 'Follow-up.'
    ): AIExplanation {
        return AIExplanation::create([
            'student_id' => $primary->student_id,
            'assessment_id' => $primary->assessment_id,
            'assessment_submission_id' => $primary->assessment_submission_id,
            'item_id' => $primary->item_id,
            'explanation_text' => $text,
            'is_ungrounded' => true,
            'is_follow_up' => true,
            'parent_explanation_id' => $primary->id,
            'turn_number' => $turnNumber,
            'flagged' => false,
            'explain_further_disabled' => false]);
    }

    // ========================================================================
    // #87 — GET /api/student/assessments/{id}/explanations
    // ========================================================================

    /** @test */
    public function test_87_list_returns_401_when_unauthenticated()
    {
        $this->setUpOrg();

        $response = $this->getJson('/api/student/assessments/1/explanations');

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_87_list_nonexistent_assessment_returns_404()
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/assessments/999999/explanations');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_87_list_without_released_results_returns_404()
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Submit but DO NOT release results — #87 requires a released submission.
        $this->studentStartAndSubmit($assessment->id, ['X']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_87_list_all_correct_answers_returns_empty_data()
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // All-correct answers: no unmastered competency, no explanation triggered.
        $this->studentStartAndSubmitAndRelease($assessment->id, ['A']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    /** @test */
    public function test_87_list_includes_follow_up_entries_ordered_after_primary()
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
        $primary = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $followUp1 = $this->createFollowUp($primary, 1, 'Follow-up turn 1.');
        $followUp2 = $this->createFollowUp($primary, 2, 'Follow-up turn 2.');

        // Normalize created_at so ordering is deterministic: created_at desc
        // then turn_number asc puts the primary (turn 0) first.
        $primaryCreatedAt = $primary->created_at;
        AIExplanation::query()->where('id', $followUp1->id)->update(['created_at' => $primaryCreatedAt]);
        AIExplanation::query()->where('id', $followUp2->id)->update(['created_at' => $primaryCreatedAt]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);
        // Follow-ups come after the primary, ordered by turn_number asc.
        $this->assertEquals(0, $data[0]['turn_number']);
        $this->assertEquals(1, $data[1]['turn_number']);
        $this->assertEquals(2, $data[2]['turn_number']);
        $this->assertEquals($primary->item_id, $data[0]['item_id']);
        $this->assertEquals($primary->item_id, $data[1]['item_id']);
        $this->assertEquals($primary->item_id, $data[2]['item_id']);
        $this->assertEquals($primary->explanation_text, $data[0]['explanation_text']);
        $this->assertEquals('Follow-up turn 1.', $data[1]['explanation_text']);
        $this->assertEquals('Follow-up turn 2.', $data[2]['explanation_text']);
    }

    /** @test */
    public function test_87_list_entry_shape_values_match_response_and_correct_answer()
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'What is 1/2 + 1/2?',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertOk();
        $entry = $response->json('data.0');
        $this->assertNotNull($entry);
        $this->assertEquals('X', $entry['student_response']);
        $this->assertEquals('A', $entry['correct_answer']);
        $this->assertEquals('What is 1/2 + 1/2?', $entry['item_text']);
        $this->assertEquals('M7-NUM-01', $entry['competency_code']);
        $this->assertTrue($entry['is_ungrounded']);
    }

    /** @test */
    public function test_87_list_entries_include_id_and_is_follow_up()
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
        $primary = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);
        $followUp = $this->createFollowUp($primary, 1, 'Follow-up turn 1.');

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Every entry must expose id (int) and is_follow_up (bool).
        foreach ($data as $entry) {
            $this->assertArrayHasKey('id', $entry);
            $this->assertIsInt($entry['id']);
            $this->assertArrayHasKey('is_follow_up', $entry);
            $this->assertIsBool($entry['is_follow_up']);
        }

        $byId = collect($data)->keyBy('id');
        $this->assertArrayHasKey($primary->id, $byId);
        $this->assertFalse($byId[$primary->id]['is_follow_up']);
        $this->assertArrayHasKey($followUp->id, $byId);
        $this->assertTrue($byId[$followUp->id]['is_follow_up']);
    }

    // ========================================================================
    // #88 — GET /api/student/explanations/{id}
    // ========================================================================

    /** @test */
    public function test_88_show_returns_401_when_unauthenticated()
    {
        $this->setUpOrg();

        $response = $this->getJson('/api/student/explanations/1');

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_88_show_nonexistent_explanation_returns_404()
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/student/explanations/999999');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_88_show_other_students_explanation_returns_404()
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

        $response = $this->actingAs($this->studentB, 'sanctum')
            ->getJson("/api/student/explanations/{$explanation->id}");

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_88_show_teacher_role_returns_403()
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
            ->getJson("/api/student/explanations/{$explanation->id}");

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_88_show_returns_full_value_assertions()
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
            ->getJson("/api/student/explanations/{$explanation->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($explanation->id, $data['id']);
        $this->assertEquals('X', $data['student_response']);
        $this->assertEquals('A', $data['correct_answer']);
        $this->assertEquals('multiple_choice', $data['item_type']);
        $this->assertEquals('M7-NUM-01', $data['competency_code']);
        $this->assertTrue($data['explain_further_available']);
        $this->assertEquals(2, $data['explain_further_turns_remaining']);
        $this->assertTrue($data['has_disclaimers']['standard_ai_disclaimer']);
        // No learning material exists → RAG fallback → ungrounded disclaimer shown.
        $this->assertTrue($data['is_ungrounded']);
        $this->assertTrue($data['has_disclaimers']['ungrounded_disclaimer']);
    }

    /** @test */
    public function test_88_show_follow_up_detail_returns_follow_up_flags()
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
        $primary = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);
        $followUp = $this->createFollowUp($primary, 1, 'Follow-up turn 1.');

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/explanations/{$followUp->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($followUp->id, $data['id']);
        $this->assertTrue($data['is_follow_up']);
        $this->assertEquals(1, $data['turn_number']);
    }

    /** @test */
    public function test_88_show_turns_remaining_decreases_with_follow_ups()
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
        $primary = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);

        // One follow-up → still available, 1 turn left.
        $this->createFollowUp($primary, 1, 'Follow-up turn 1.');
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/explanations/{$primary->id}");
        $response->assertOk();
        $this->assertTrue($response->json('data.explain_further_available'));
        $this->assertEquals(1, $response->json('data.explain_further_turns_remaining'));

        // Two follow-ups → no longer available, 0 turns left.
        $this->createFollowUp($primary, 2, 'Follow-up turn 2.');
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/explanations/{$primary->id}");
        $response->assertOk();
        $this->assertFalse($response->json('data.explain_further_available'));
        $this->assertEquals(0, $response->json('data.explain_further_turns_remaining'));
    }

    /** @test */
    public function test_88_show_includes_teacher_note()
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

        $explanation->update(['teacher_note' => 'Reviewed by teacher.']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/explanations/{$explanation->id}");

        $response->assertOk();
        $this->assertEquals('Reviewed by teacher.', $response->json('data.teacher_note'));
    }

    // ========================================================================
    // #89 — POST /api/student/explanations/{id}/explain-further
    // ========================================================================

    /** @test */
    public function test_89_explain_further_returns_401_when_unauthenticated()
    {
        $this->setUpOrg();

        $response = $this->postJson('/api/student/explanations/1/explain-further', ['item_id' => 1]);

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_89_explain_further_missing_item_id_returns_422()
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
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code', 'fields']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('item_id', $response->json('error.fields'));
    }

    /** @test */
    public function test_89_explain_further_nonexistent_item_returns_404()
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

        // No `exists` rule on the FormRequest — the service-level findOrFail
        // must yield 404, NOT 422.
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => 999999]);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_89_explain_further_item_from_different_assessment_returns_404()
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

        // Same student, different assessment — the item is not part of the
        // follow-up chain's assessment and must be rejected.
        $otherAssessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $foreignItem = AssessmentItem::where('assessment_id', $otherAssessment->id)->first();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $foreignItem->id]);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));

        // Pre-fix stored a follow-up bound to the foreign item — assert none.
        $this->assertDatabaseMissing('ai_explanations', [
            'parent_explanation_id' => $explanation->id]);
    }

    /** @test */
    public function test_89_explain_further_other_students_explanation_returns_404()
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
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->studentB, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_89_explain_further_true_false_item_success()
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'true_false',
                'prompt' => '2 + 2 = 4.',
                'max_points' => 10,
                'correct_answer' => 'true',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Student answers wrongly so a primary explanation is generated.
        $this->studentStartAndSubmitAndRelease($assessment->id, ['false']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertOk();
        $response->assertJsonPath('data.success', true);
        $response->assertJsonPath('data.is_follow_up', true);
        $response->assertJsonPath('data.turn_number', 1);
        $response->assertJsonPath('data.item_id', $item->id);
    }

    /** @test */
    public function test_89_explain_further_turns_remaining_decrements()
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
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.turns_remaining'));

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);
        $response->assertOk();
        $this->assertEquals(0, $response->json('data.turns_remaining'));
    }

    /** @test */
    public function test_89_explain_further_stores_follow_up_row()
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
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertOk();
        $this->assertDatabaseHas('ai_explanations', [
            'parent_explanation_id' => $explanation->id,
            'student_id' => $this->student->id,
            'assessment_id' => $assessment->id,
            'assessment_submission_id' => $explanation->assessment_submission_id,
            'item_id' => $item->id,
            'is_follow_up' => true,
            'turn_number' => 1]);
    }

    /** @test */
    public function test_89_explain_further_stores_grounded_when_learning_material_exists()
    {
        Storage::fake('local');
        $this->setUpOrg();

        // Upload a real minimal PDF so upload-time extraction (smalot/pdfparser,
        // ARCH-002 FR-026/WU-7) stores RAG text.
        $this->actingAs($this->teacher, 'sanctum')
            ->post('/api/teacher/learning-materials', [
                'subject_id' => $this->subject->id,
                'competency_id' => $this->competencyTag1->id,
                'title' => 'Rational Numbers Guide',
                'file' => File::fake()->createWithContent('guide.pdf', $this->minimalPdf('Useful guide text'))]);

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);
        $explanation = $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertOk();
        // RAG material present → follow-up is grounded (no ungrounded disclaimer).
        $this->assertDatabaseHas('ai_explanations', [
            'parent_explanation_id' => $explanation->id,
            'is_follow_up' => true,
            'turn_number' => 1,
            'is_ungrounded' => false]);
    }

    /** @test */
    public function test_89_explain_further_teacher_role_returns_403()
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
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $item->id]);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    // ========================================================================
    // Password-change gate (#87, #88)
    // ========================================================================

    /** @test */
    public function test_87_list_password_change_required_returns_403()
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $response = $this->actingAs($this->passwordChangeStudent, 'sanctum')
            ->getJson("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_88_show_password_change_required_returns_403()
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

        $response = $this->actingAs($this->passwordChangeStudent, 'sanctum')
            ->getJson("/api/student/explanations/{$explanation->id}");

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }
}
