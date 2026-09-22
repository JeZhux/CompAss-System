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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 *
 * Closes the gaps the baseline Phase7Test does not assert for the Teacher
 * Moderation Log endpoints (#90–#94, API Interface Spec §3.9.3): pagination,
 * assessment/section filters, cross-teacher isolation, ownership 403s,
 * validation on the note body, and the ARCH-002 FR-030 / ARCH-002 FR-030 invariants.
 *
 * Endpoints:
 *   #90 GET  /api/teacher/moderation-log
 *   #91 GET  /api/teacher/moderation-log/{id}
 *   #92 POST /api/teacher/moderation-log/{id}/flag
 *   #93 POST /api/teacher/moderation-log/{id}/note
 *   #94 POST /api/teacher/moderation-log/{id}/disable-explain-further
 *
 * Middleware (registered): auth:sanctum → password.change.required → role:teacher;
 * effective order on the moderation-WRITE routes #92–#94:
 * auth:sanctum → throttle:moderation-write → password.change.required → role:teacher
 * (the vendor default priority list sorts ThrottleRequests right after
 * Authenticate, ahead of both custom gates).
 * Error envelope: { "error": { "message", "code", "fields?" } } (ARCH-002 QA-007).
 */
#[Group('phase7-moderation-log')]
class Phase7ModerationLogGapsTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;
    private ?User $student = null;
    private ?User $mustChangeTeacher = null;
    private ?Subject $subject = null;
    private ?Section $section = null;
    private ?Classroom $classroom = null;
    private ?CompetencyReference $competencyTag1 = null;
    private ?CompetencyReference $competencyTag2 = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->student = $this->mustChangeTeacher = null;
        $this->subject = $this->section = null;
        $this->classroom = null;
        $this->competencyTag1 = $this->competencyTag2 = null;

        // ARCH-006 §6: AI generation is mock-gated in tests — no OpenRouter wire.
        config()->set('services.openrouter.mock', true);
    }

    /**
     * Build a full org hierarchy with TWO competency tags for the main teacher
     * and main student (teacher assigned, student enrolled).
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-MG']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-MG']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-MG']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-001',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GE-002',
            'descriptor' => 'Classify geometric figures',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);

        $this->teacher = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => false]);
        $this->student = User::factory()->create([
            'role' => 'Student',
            'name' => 'Alice Student']);
        $this->mustChangeTeacher = User::factory()->create([
            'role' => 'Teacher',
            'must_change_password' => true]);

        $this->classroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $this->subject->id, $this->section->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $this->classroom->id,
            'student_id' => $this->student->id,
            'joined_at' => now()]);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user);
    }

    /**
     * Create a released Recorded assessment, optionally in a specific
     * subject and for a specific teacher (both default to the main org).
     */
    private function createReleasedAssessment(
        string $type,
        array $items,
        ?Subject $subject = null,
        ?User $teacher = null,
        ?Classroom $classroom = null
    ): Assessment {
        $subject = $subject ?? $this->subject;
        $teacher = $teacher ?? $this->teacher;
        $classroom = $classroom ?? $this->classroom;

        $assessment = Assessment::create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Phase 7 ' . $type . ' MG Test',
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

    /**
     * Create one released assessment with a single competency-tagged item,
     * run the full student flow, and return the stored explanation.
     */
    private function seedSingleExplanation(): AIExplanation
    {
        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessment->id, ['X']);

        return $this->generateExplanation($this->student->id, $assessment->id, $this->competencyTag1->id);
    }

    /**
     * Create a second teacher's org (own section, subject, student)
     * with one released Recorded assessment and one stored explanation.
     * Returns the second teacher, student, subject, and explanation.
     *
     * @return array{teacher: User, student: User, subject: Subject, explanation: AIExplanation}
     */
    private function seedSecondTeacherExplanation(): array
    {
        $secondSection = Section::create([
            'grade_level_id' => $this->section->gradeLevel->id,
            'name' => '7B-MG']);
        $secondSubject = Subject::create(['grade_level_id' => $this->section->gradeLevel->id, 'name' => 'Science', 'code' => 'SCI7-MG']);
        $secondTag = CompetencyReference::create(['semester' => '1', 'code' => 'S7-LIFE-001',
            'descriptor' => 'Describe cell structure',
            'subject_id' => $secondSubject->id,
            'grade_level' => '7']);

        $secondTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);
        $secondStudent = User::factory()->create([
            'role' => 'Student',
            'name' => 'Bob Student']);

        $secondClassroom = app(ClassroomService::class)->createClassroom($secondTeacher->id, $secondSubject->id, $secondSection->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $secondClassroom->id,
            'student_id' => $secondStudent->id,
            'joined_at' => now()]);

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'SQ1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $secondTag->id]], $secondSubject, $secondTeacher, $secondClassroom);

        $this->actingAs($secondStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/start");
        $item = AssessmentItem::where('assessment_id', $assessment->id)->first();
        $this->actingAs($secondStudent, 'sanctum')
            ->post("/api/student/assessments/{$assessment->id}/submit", [
                'responses' => [(string) $item->id => 'X']]);
        $this->actingAs($secondTeacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessment->id}/release-results");

        $this->app->make(AIService::class)->generateSingleExplanation(
            $secondStudent->id,
            $assessment->id,
            $secondTag->id
        );

        $explanation = AIExplanation::where('student_id', $secondStudent->id)
            ->where('assessment_id', $assessment->id)
            ->where('is_follow_up', false)
            ->first();

        return [
            'teacher' => $secondTeacher,
            'student' => $secondStudent,
            'subject' => $secondSubject,
            'explanation' => $explanation];
    }

    /**
     * Create two Recorded assessments (one with two competency-tagged items,
     * one with a single item) and store 3 explanations total (2 + 1) for the
     * main student. Returns [assessmentA, assessmentB].
     *
     * @return array{0: Assessment, 1: Assessment}
     */
    private function seedThreeExplanations(): array
    {
        $assessmentA = $this->createReleasedAssessment('Recorded', [
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
        $assessmentB = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q3',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->studentStartAndSubmitAndRelease($assessmentA->id, ['X', 'X']);
        $this->studentStartAndSubmitAndRelease($assessmentB->id, ['X']);

        // Explicit-request-only AI (ARCH-002 FR-024): release stores nothing — these
        // direct generation calls are the only source of the rows the
        // moderation-log tests assert on.
        $aiService = $this->app->make(AIService::class);
        $aiService->generateSingleExplanation($this->student->id, $assessmentA->id, $this->competencyTag1->id);
        $aiService->generateSingleExplanation($this->student->id, $assessmentA->id, $this->competencyTag2->id);
        $aiService->generateSingleExplanation($this->student->id, $assessmentB->id, $this->competencyTag1->id);

        return [$assessmentA, $assessmentB];
    }

    // ====================================================================
    // #90 — GET /api/teacher/moderation-log
    // ====================================================================

    /** @test */
    public function test_90_moderation_log_list_returns_401_when_unauthenticated()
    {
        $response = $this->getJson('/api/teacher/moderation-log');

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_90_moderation_log_list_returns_403_for_student()
    {
        $this->setUpOrg();

        $this->actAs($this->student);
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/teacher/moderation-log');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_90_moderation_log_list_returns_403_for_must_change_password_teacher()
    {
        $this->setUpOrg();

        $this->actAs($this->mustChangeTeacher);
        $response = $this->actingAs($this->mustChangeTeacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    /** @test */
    public function test_90_moderation_log_list_pagination_meta_and_pages()
    {
        $this->setUpOrg();
        $this->seedThreeExplanations();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?per_page=1');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [],
            'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total']]);
        $this->assertEquals(3, $response->json('meta.total'));
        $this->assertEquals(3, $response->json('meta.last_page'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(1, $response->json('meta.per_page'));
        $this->assertEquals(1, $response->json('meta.from'));
        $this->assertEquals(1, $response->json('meta.to'));
        $this->assertCount(1, $response->json('data'));
        $firstPageId = $response->json('data.0.id');

        $pageTwo = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?per_page=1&page=2');

        $pageTwo->assertOk();
        $this->assertCount(1, $pageTwo->json('data'));
        $this->assertEquals(3, $pageTwo->json('meta.total'));
        $this->assertEquals(2, $pageTwo->json('meta.current_page'));
        $this->assertNotEquals($firstPageId, $pageTwo->json('data.0.id'));
    }

    /** @test */
    public function test_90_moderation_log_list_clamps_per_page_lower_bound()
    {
        $this->setUpOrg();
        $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        // per_page=0 must not crash (pre-fix: division by zero) — clamped to
        // the 15 default (ARCH-005 §2 shared clamp, out-of-range → default).
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?per_page=0');

        $response->assertOk();
        $this->assertEquals(15, $response->json('meta.per_page'));
    }

    /** @test */
    public function test_90_moderation_log_list_clamps_per_page_upper_bound()
    {
        $this->setUpOrg();
        $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        // per_page=999999 must be capped at the 100 ceiling.
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?per_page=999999');

        $response->assertOk();
        $this->assertEquals(100, $response->json('meta.per_page'));
    }

    /** @test */
    public function test_90_moderation_log_list_filters_by_assessment_id()
    {
        $this->setUpOrg();
        [$assessmentA, $assessmentB] = $this->seedThreeExplanations();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?assessment_id=' . $assessmentA->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $entry) {
            $this->assertEquals($assessmentA->id, $entry['assessment_id']);
        }

        $single = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?assessment_id=' . $assessmentB->id);

        $single->assertOk();
        $this->assertCount(1, $single->json('data'));
        $this->assertEquals($assessmentB->id, $single->json('data.0.assessment_id'));
    }

    /** @test */
    public function test_90_moderation_log_list_filters_by_section_id()
    {
        $this->setUpOrg();

        // Main subject: one explanation.
        $this->seedSingleExplanation();

        // Second subject owned by the same teacher: one explanation.
        $otherSection = Section::create([
            'grade_level_id' => $this->section->gradeLevel->id,
            'name' => '7B-MG2']);
        $otherSubject = Subject::create(['grade_level_id' => $this->section->gradeLevel->id, 'name' => 'Science', 'code' => 'SCI7-MG2']);
        $otherClassroom = app(ClassroomService::class)->createClassroom($this->teacher->id, $otherSubject->id, $otherSection->id, '2026-2027', null);
        ClassroomEnrollment::create([
            'classroom_id' => $otherClassroom->id,
            'student_id' => $this->student->id,
            'joined_at' => now()]);

        $assessment2 = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q2',
                'max_points' => 10,
                'correct_answer' => 'B',
                'competency_tag_id' => $this->competencyTag1->id]], $otherSubject, null, $otherClassroom);
        $this->studentStartAndSubmitAndRelease($assessment2->id, ['X']);
        $explanation2 = $this->generateExplanation($this->student->id, $assessment2->id, $this->competencyTag1->id);

        // Filtering by the main subject returns only its own entries.
        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?section_id=' . $this->subject->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->subject->id, $data[0]['subject_id']);
        $this->assertNotEquals($explanation2->id, $data[0]['id']);

        // A subject the teacher owns but with no explanations → empty data, total 0.
        $emptySection = Section::create([
            'grade_level_id' => $this->section->gradeLevel->id,
            'name' => '7C-MG2']);
        $emptySubject = Subject::create(['grade_level_id' => $this->section->gradeLevel->id, 'name' => 'English', 'code' => 'ENG7-MG2']);
        app(ClassroomService::class)->createClassroom($this->teacher->id, $emptySubject->id, $emptySection->id, '2026-2027', null);

        $empty = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?section_id=' . $emptySubject->id);

        $empty->assertOk();
        $this->assertCount(0, $empty->json('data'));
        $this->assertEquals(0, $empty->json('meta.total'));
    }

    /** @test */
    public function test_90_moderation_log_list_unassigned_section_returns_403()
    {
        $this->setUpOrg();

        $otherSection = Section::create([
            'grade_level_id' => $this->section->gradeLevel->id,
            'name' => '7D-MG2']);
        $otherSubject = Subject::create(['grade_level_id' => $this->section->gradeLevel->id, 'name' => 'Social Studies', 'code' => 'SS7-MG2']);

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?section_id=' . $otherSubject->id);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_90_moderation_log_list_unknown_subject_returns_403()
    {
        $this->setUpOrg();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log?section_id=999999');

        // section_id is a legacy alias of the subject filter: an id the
        // teacher owns no classroom for is FORBIDDEN (same as unassigned).
        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_90_moderation_log_list_empty_for_teacher_without_assignments()
    {
        $unassignedTeacher = User::factory()->create(['role' => 'Teacher', 'must_change_password' => false]);

        $this->actAs($unassignedTeacher);
        $response = $this->actingAs($unassignedTeacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
        $this->assertEquals(0, $response->json('meta.total'));
    }

    /** @test */
    public function test_90_moderation_log_list_isolates_other_teachers_explanations()
    {
        $this->setUpOrg();
        $explanation1 = $this->seedSingleExplanation();
        $second = $this->seedSecondTeacherExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($explanation1->id, $data[0]['id']);
        $this->assertEquals($this->student->id, $data[0]['student_id']);
        $this->assertNotEquals($second['explanation']->id, $data[0]['id']);
    }

    /** @test */
    public function test_90_moderation_log_list_orders_newest_first()
    {
        $this->setUpOrg();
        $first = $this->seedSingleExplanation();
        $second = $this->seedSingleExplanation();

        // Backdate the first explanation so created_at is clearly distinct.
        $first->created_at = now()->subHour();
        $first->save();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($second->id, $data[0]['id']);
        $this->assertEquals($first->id, $data[1]['id']);
    }

    // ====================================================================
    // #91 — GET /api/teacher/moderation-log/{id}
    // ====================================================================

    /** @test */
    public function test_91_moderation_log_show_nonexistent_returns_404()
    {
        $this->setUpOrg();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log/999999');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_91_moderation_log_show_other_teachers_explanation_returns_403()
    {
        $this->setUpOrg();
        $second = $this->seedSecondTeacherExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log/' . $second['explanation']->id);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_91_moderation_log_show_returns_403_for_student()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->student);
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/teacher/moderation-log/' . $explanation->id);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_91_moderation_log_show_returns_full_shape()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/teacher/moderation-log/' . $explanation->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'student_name',
                'school_id',
                'student_id',
                'assessment_id',
                'explanation_text',
                'flagged',
                'teacher_note',
                'explain_further_disabled']]);
        $this->assertEquals($explanation->id, $response->json('data.id'));
        $this->assertEquals('Alice Student', $response->json('data.student_name'));
    }

    // ====================================================================
    // #92 — POST /api/teacher/moderation-log/{id}/flag
    // ====================================================================

    /** @test */
    public function test_92_flag_explanation_without_note()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/flag");

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Explanation flagged.');
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'flagged' => true,
            'flagged_by_teacher_id' => $this->teacher->id,
            'teacher_note' => null]);
    }

    /** @test */
    public function test_92_flag_explanation_with_note()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/flag", [
                'note' => 'Inappropriate content']);

        $response->assertOk();
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'flagged' => true,
            'flagged_by_teacher_id' => $this->teacher->id,
            'teacher_note' => 'Inappropriate content']);
        $this->assertNotNull(AIExplanation::find($explanation->id)->teacher_note_updated_at);
    }

    /** @test */
    public function test_92_flag_explanation_nonexistent_returns_404()
    {
        $this->setUpOrg();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/teacher/moderation-log/999999/flag');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_92_flag_explanation_other_teachers_returns_403_and_row_unchanged()
    {
        $this->setUpOrg();
        $second = $this->seedSecondTeacherExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$second['explanation']->id}/flag");

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $second['explanation']->id,
            'flagged' => false]);
    }

    /** @test */
    public function test_92_flag_explanation_returns_403_for_student()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->student);
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/flag");

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    // ====================================================================
    // #93 — POST /api/teacher/moderation-log/{id}/note
    // ====================================================================

    /** @test */
    public function test_93_append_note_success()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/note", [
                'note' => 'Reviewed by teacher.']);

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Teacher note appended.');
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'teacher_note' => 'Reviewed by teacher.']);
        $this->assertNotNull(AIExplanation::find($explanation->id)->teacher_note_updated_at);
    }

    /** @test */
    public function test_93_append_note_overwrites_previous_note()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/note", [
                'note' => 'Note A']);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/note", [
                'note' => 'Note B']);

        $response->assertOk();
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'teacher_note' => 'Note B']);
        $this->assertNotNull(AIExplanation::find($explanation->id)->teacher_note_updated_at);
    }

    /** @test */
    public function test_93_append_note_missing_returns_422()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/note");

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code', 'fields']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('note', $response->json('error.fields'));
    }

    /** @test */
    public function test_93_append_note_empty_string_returns_422()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/note", [
                'note' => '']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code', 'fields']]);
        $this->assertEquals('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertArrayHasKey('note', $response->json('error.fields'));
    }

    /** @test */
    public function test_93_append_note_nonexistent_returns_404()
    {
        $this->setUpOrg();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/teacher/moderation-log/999999/note', [
                'note' => 'A note.']);

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_93_append_note_other_teachers_returns_403_and_row_unchanged()
    {
        $this->setUpOrg();
        $second = $this->seedSecondTeacherExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$second['explanation']->id}/note", [
                'note' => 'Intruder note.']);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $second['explanation']->id,
            'teacher_note' => null]);
    }

    /** @test */
    public function test_93_append_note_returns_403_for_student()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->student);
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/note", [
                'note' => 'A note.']);

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    // ====================================================================
    // #94 — POST /api/teacher/moderation-log/{id}/disable-explain-further
    // ====================================================================

    /** @test */
    public function test_94_disable_explain_further_success()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/disable-explain-further");

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Explain-further disabled for this explanation.');
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $explanation->id,
            'explain_further_disabled' => true,
            'disabled_by_teacher_id' => $this->teacher->id]);
    }

    /** @test */
    public function test_94_disable_explain_further_nonexistent_returns_404()
    {
        $this->setUpOrg();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/teacher/moderation-log/999999/disable-explain-further');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_94_disable_explain_further_other_teachers_returns_403_and_row_unchanged()
    {
        $this->setUpOrg();
        $second = $this->seedSecondTeacherExplanation();

        $this->actAs($this->teacher);
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$second['explanation']->id}/disable-explain-further");

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
        $this->assertDatabaseHas('ai_explanations', [
            'id' => $second['explanation']->id,
            'explain_further_disabled' => false]);
    }

    /** @test */
    public function test_94_disable_explain_further_returns_403_for_student()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->student);
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/disable-explain-further");

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_94_disable_explain_further_e2e_blocks_student_follow_up()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        // Teacher disables follow-ups via the #94 endpoint.
        $this->actAs($this->teacher);
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/disable-explain-further");

        // Student's Explain Further attempt is now rejected.
        $this->actAs($this->student);
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/explanations/{$explanation->id}/explain-further", [
                'item_id' => $explanation->item_id]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('EXPLAIN_FURTHER_DISABLED', $response->json('error.code'));
    }

    // ====================================================================
    // §2.7 — moderation-WRITE rate limiter (AUD-012): 60/min per teacher
    // ====================================================================

    /** @test */
    public function test_moderation_write_rate_limit_allows_60_then_429_with_envelope()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        $this->actAs($this->teacher);
        // Flagging is idempotent (AIService re-saves the same values), so one
        // stored explanation sustains 60 genuinely successful writes.
        for ($i = 0; $i < 60; $i++) {
            $response = $this->postJson("/api/teacher/moderation-log/{$explanation->id}/flag");
            $this->assertSame(
                200,
                $response->getStatusCode(),
                'Write #' . ($i + 1) . ' must succeed inside the 60/min budget.'
            );
        }

        // The 61st write within the window is rejected with the canonical
        // envelope and the X-RateLimit-* transport headers preserved (ARCH-002 QA-008).
        $response = $this->postJson("/api/teacher/moderation-log/{$explanation->id}/flag");

        $response->assertStatus(429);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $response->json('error.code'));
        $response->assertHeader('X-RateLimit-Limit', '60');
        $response->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));

        // All three write endpoints share the one per-teacher bucket
        // (#93 and #94 stay throttled on A's exhausted budget).
        $note = $this->postJson("/api/teacher/moderation-log/{$explanation->id}/note", [
            'note' => 'Still throttled.']);
        $note->assertStatus(429);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $note->json('error.code'));

        $disable = $this->postJson("/api/teacher/moderation-log/{$explanation->id}/disable-explain-further");
        $disable->assertStatus(429);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $disable->json('error.code'));
    }

    /** @test */
    public function test_moderation_write_rate_limit_counter_is_independent_per_teacher()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();
        $second = $this->seedSecondTeacherExplanation();

        // Exhaust teacher A's budget with genuine writes.
        $this->actAs($this->teacher);
        for ($i = 0; $i < 60; $i++) {
            $this->postJson("/api/teacher/moderation-log/{$explanation->id}/flag");
        }
        $this->postJson("/api/teacher/moderation-log/{$explanation->id}/flag")
            ->assertStatus(429);

        // Effective order on these routes: auth:sanctum →
        // throttle:moderation-write → password.change.required → role:teacher
        // (the vendor default priority list sorts ThrottleRequests right
        // after Authenticate, ahead of both custom gates). Both teachers
        // share this test client's IP and both pass every gate, so if the
        // bucket were keyed by IP or module-global, B would inherit A's
        // exhaustion and 429 too. Corollary of throttle running ahead of the
        // gates: wrong-role or must-change-password callers consume their
        // OWN id-keyed bucket and receive 429 RATE_LIMIT_EXCEEDED instead of
        // 403 / PASSWORD_CHANGE_REQUIRED once their own budget is spent —
        // accepted intended behaviour (self-keyed only, no privilege gain).
        $response = $this->actingAs($second['teacher'], 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$second['explanation']->id}/flag");

        $response->assertOk();
        $response->assertJsonPath('data.message', 'Explanation flagged.');
    }

    /** @test */
    public function test_moderation_write_rate_limit_does_not_throttle_get_endpoints()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        // Exhaust teacher A's write budget.
        $this->actAs($this->teacher);
        for ($i = 0; $i < 60; $i++) {
            $this->postJson("/api/teacher/moderation-log/{$explanation->id}/flag");
        }
        $this->postJson("/api/teacher/moderation-log/{$explanation->id}/flag")
            ->assertStatus(429);

        // GET #90/#91 carry no moderation-write throttle — still 200.
        $list = $this->getJson('/api/teacher/moderation-log');
        $list->assertOk();

        $show = $this->getJson('/api/teacher/moderation-log/' . $explanation->id);
        $show->assertOk();
    }

    // ====================================================================
    // ARCH-002 FR-030 — flagged explanations remain visible to the student
    // ====================================================================

    /** @test */
    public function test_br61_flagged_explanation_still_visible_to_student()
    {
        $this->setUpOrg();
        $explanation = $this->seedSingleExplanation();

        // Teacher flags the explanation with a note via #92.
        $this->actAs($this->teacher);
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/teacher/moderation-log/{$explanation->id}/flag", [
                'note' => 'Verify accuracy before using.']);

        // The student can still fetch it — flag does NOT hide it (ARCH-002 FR-030).
        $this->actAs($this->student);
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/explanations/{$explanation->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $explanation->id);
        $response->assertJsonPath('data.flagged', true);
        $response->assertJsonPath('data.teacher_note', 'Verify accuracy before using.');
    }
}
