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
use App\Services\ClassroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature-level tests for the explicit student-initiated AI explanation
 * generation endpoint:
 *
 *   POST /api/student/assessments/{id}/explanations/generate
 *
 * Contract under test: generation runs ONLY on explicit request after the
 * teacher released results (ARCH-002 FR-027 control on the results view); the response
 * carries the full #87-shaped payload; persistence is idempotent per
 * (attempt, item); outages surface as 503 AI_SERVICE_UNAVAILABLE with nothing
 * persisted; access is student-only behind the standard middleware stack plus
 * a DEDICATED per-student rate limiter — never keyed by IP (ARCH-002 QA-003: a class
 * behind shared NAT cannot block or fail each other's requests).
 */
#[Group('phase7-explanation-generate')]
class Phase7ExplanationGenerateTest extends TestCase
{
    use RefreshDatabase;

    private ?User $teacher = null;
    private ?User $student = null;
    private ?User $studentB = null;
    private ?Subject $subject = null;
    private ?Section $section = null;
    private ?Classroom $classroom = null;
    private ?CompetencyReference $competencyTag1 = null;
    private ?CompetencyReference $competencyTag2 = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->student = $this->studentB = null;
        $this->subject = $this->section = null;
        $this->classroom = null;
        $this->competencyTag1 = $this->competencyTag2 = null;

        // ARCH-006 §6: AI generation is mock-gated in tests — the OpenRouter wire
        // is never touched unless a test explicitly disables the gate.
        config()->set('services.openrouter.mock', true);
    }

    /**
     * Build a full org hierarchy with TWO competency tags and two students
     * (A and B) for the ARCH-002 QA-003 fairness test.
     */
    private function setUpOrg(): void
    {
        $year = SchoolYear::create(['name' => 'SY 2026-P7GEN']);
        $semester = Semester::create(['semester' => '1', 'school_year_id' => $year->id,
            'name' => 'Semester 1',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31']);
        $gradeLevel = GradeLevel::create([
            'semester_id' => $semester->id,
            'grade_level' => '7']);
        $this->section = Section::create([
            'grade_level_id' => $gradeLevel->id,
            'name' => '7A-P7GEN']);
$this->subject = Subject::create(['grade_level_id' => $gradeLevel->id, 'name' => 'Mathematics', 'code' => 'MATH7-P7GEN']);
        
        $this->competencyTag1 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-NUM-01G',
            'descriptor' => 'Solve multi-step rational numbers',
            'subject_id' => $this->subject->id,
            'grade_level' => '7']);
        $this->competencyTag2 = CompetencyReference::create(['semester' => '1', 'code' => 'M7-GEO-02G',
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
     * Create a released-status assessment with the given items.
     */
    private function createReleasedAssessment(string $type, array $items): Assessment
    {
        $assessment = Assessment::create([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'semester_id' => $this->section->gradeLevel->semester_id,
            'title' => 'Phase 7 Generate ' . $type . ' Test',
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
     * Start + submit the assessment as the given student (results NOT released).
     */
    private function startAndSubmit(User $student, int $assessmentId, array $responses): void
    {
        $this->actingAs($student, 'sanctum')
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

        $this->actingAs($student, 'sanctum')
            ->post("/api/student/assessments/{$assessmentId}/submit", [
                'responses' => $mappedResponses]);
    }

    /**
     * Start + submit as the given student, then teacher releases results.
     */
    private function startAndSubmitAndRelease(User $student, int $assessmentId, array $responses): void
    {
        $this->startAndSubmit($student, $assessmentId, $responses);

        $this->actingAs($this->teacher, 'sanctum')
            ->post("/api/teacher/assessments/{$assessmentId}/release-results");
    }

    // ========================================================================
    // Middleware posture (#87 parity)
    // ========================================================================

    /** @test */
    public function test_generate_returns_401_when_unauthenticated()
    {
        $this->setUpOrg();

        $response = $this->postJson('/api/student/assessments/1/explanations/generate');

        $response->assertStatus(401);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('UNAUTHENTICATED', $response->json('error.code'));
    }

    /** @test */
    public function test_generate_teacher_role_returns_403_forbidden()
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/student/assessments/1/explanations/generate');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_generate_admin_role_returns_403_forbidden()
    {
        $this->setUpOrg();

        $admin = User::factory()->create(['role' => 'Admin', 'must_change_password' => false]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/student/assessments/1/explanations/generate');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('FORBIDDEN', $response->json('error.code'));
    }

    /** @test */
    public function test_generate_password_change_required_returns_403()
    {
        $this->setUpOrg();

        $gatedStudent = User::factory()->create([
            'role' => 'Student',
            'must_change_password' => true,
            'name' => 'Carol Student']);

        $response = $this->actingAs($gatedStudent, 'sanctum')
            ->postJson('/api/student/assessments/1/explanations/generate');

        $response->assertStatus(403);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('PASSWORD_CHANGE_REQUIRED', $response->json('error.code'));
    }

    // ========================================================================
    // Release gate + happy path
    // ========================================================================

    /** @test */
    public function test_generate_nonexistent_assessment_returns_404_not_found()
    {
        $this->setUpOrg();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/student/assessments/999999/explanations/generate');

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
    }

    /** @test */
    public function test_generate_without_released_results_returns_404_and_writes_no_rows()
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Submit but DO NOT release results — generation requires release.
        $this->startAndSubmit($this->student, $assessment->id, ['X']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertEquals('NOT_FOUND', $response->json('error.code'));
        $this->assertDatabaseCount('ai_explanations', 0);
    }

    /** @test */
    public function test_generate_creates_rows_exactly_for_incorrect_items_keyed_to_attempt()
    {
        $this->setUpOrg();

        // Three objective items across two competencies; the student answers
        // item 1 and item 3 wrongly, item 2 correctly.
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
                'competency_tag_id' => $this->competencyTag2->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q3',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag2->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X', 'B', 'Y']);

        $wrongItems = AssessmentItem::where('assessment_id', $assessment->id)
            ->whereIn('prompt', ['Q1', 'Q3'])
            ->pluck('id')
            ->sort()
            ->values();
        $correctItem = AssessmentItem::where('assessment_id', $assessment->id)
            ->where('prompt', 'Q2')
            ->first();

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        $response->assertOk();
        $data = $response->json('data');

        // EXACTLY the incorrectly answered items — the correct one is absent.
        $this->assertCount(2, $data);
        $this->assertEquals(
            $wrongItems->all(),
            collect($data)->pluck('item_id')->sort()->values()->all()
        );
        $this->assertNotContains($correctItem->id, collect($data)->pluck('item_id')->all());

        // Rows persisted in ai_explanations keyed to the attempt.
        $attemptId = AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $this->student->id)
            ->value('attempt_id');
        $this->assertNotNull($attemptId);

        foreach ($wrongItems as $itemId) {
            $this->assertDatabaseHas('ai_explanations', [
                'student_id' => $this->student->id,
                'assessment_id' => $assessment->id,
                'item_id' => $itemId,
                'assessment_attempt_id' => $attemptId,
                'is_follow_up' => false,
                'turn_number' => 0,
                'moderation_status' => 'none']);
        }
        $this->assertDatabaseCount('ai_explanations', 2);
    }

    /** @test */
    public function test_generate_all_correct_answers_returns_empty_list_without_generation()
    {
        $this->setUpOrg();
        Http::fake();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['A']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertDatabaseCount('ai_explanations', 0);
        Http::assertNothingSent();
    }

    // ========================================================================
    // Idempotency (ARCH-002 FR-027 repeat-reuse)
    // ========================================================================

    /** @test */
    public function test_generate_is_idempotent_on_repeat_request()
    {
        $this->setUpOrg();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X']);

        $first = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");
        $first->assertOk();

        $second = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");
        $second->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));

        // Zero new rows; created_at untouched (no delete-and-regenerate).
        $rows = AIExplanation::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $assessment->id)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame(
            $first->json('data.0.created_at'),
            $rows->first()->created_at->toIso8601String()
        );
    }

    // ========================================================================
    // Upstream outage
    // ========================================================================

    /** @test */
    public function test_generate_upstream_failure_returns_503_and_persists_nothing()
    {
        $this->setUpOrg();
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

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');
        $this->assertDatabaseCount('ai_explanations', 0);
    }

    // ========================================================================
    // ARCH-002 QA-003: dedicated per-student rate limiter (never keyed by IP)
    // ========================================================================

    /** @test */
    public function test_generate_throttle_exhausts_one_student_only_and_fairness_holds()
    {
        $this->setUpOrg();

        // Student B will generate normally after student A exhausts the
        // per-student budget. Both share ONE test-client IP: if the limiter
        // were keyed by IP, B would inherit A's exhausted bucket and 429.
        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);
        $this->startAndSubmitAndRelease($this->studentB, $assessment->id, ['X']);

        // Drive student A past the per-minute budget (10/min): each request
        // passes the middleware stack and consumes one token (404 body —
        // A has no released submission — but the throttle counts it anyway).
        Sanctum::actingAs($this->student);
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/student/assessments/999999/explanations/generate');
            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                'Request ' . ($i + 1) . ' must not be throttled yet (limit is 10/min).'
            );
        }

        $response = $this->postJson('/api/student/assessments/999999/explanations/generate');
        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0');

        // ARCH-002 QA-003 fairness: student B's FIRST generate still succeeds and
        // generates normally.
        $response = $this->actingAs($this->studentB, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);

        $attemptId = AssessmentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->where('student_id', $this->studentB->id)
            ->value('attempt_id');
        $this->assertDatabaseHas('ai_explanations', [
            'student_id' => $this->studentB->id,
            'assessment_id' => $assessment->id,
            'assessment_attempt_id' => $attemptId,
            'is_follow_up' => false,
            'turn_number' => 0]);
    }

    // ========================================================================
    // Permanent regressions (adversarial audit): ARCH-002 QA-003 per-student keying
    // under IP rotation, pure-rejection side-effect freedom, partial-outage
    // persistence contract (RT-7), cross-student IDOR.
    // ========================================================================

    /**
     * ARCH-002 FR-024 / ARCH-002 FR-027 / UC-36 permanent pin: with mock gate ON, a released
     * submission containing INCORRECT answers and ZERO stored explanations
     * renders an EMPTY #87 list, and viewing changes nothing — no rows exist
     * afterward. Reviewing never triggers generation; eligible (attempt,
     * item) pairs stay absent until the student explicitly POSTs generate.
     */
    /** @test */
    public function test_87_view_with_released_submission_and_zero_stored_rows_returns_empty_and_never_generates()
    {
        $this->setUpOrg();
        Http::fake();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/student/assessments/{$assessment->id}/explanations");

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_explanations', 0);
    }

    /**
     * ARCH-002 QA-003 permanent pin: the ai-generate budget follows the STUDENT across
     * arbitrarily rotated client IPs. Every request below carries a different
     * spoofed REMOTE_ADDR; an IP-keyed limiter would hand each fresh IP its
     * own full bucket and never return 429. Per-student keying must exhaust
     * exactly at the 11th request.
     */
    /** @test */
    public function test_generate_throttle_keys_on_student_not_ip_surviving_ip_rotation()
    {
        $this->setUpOrg();

        Sanctum::actingAs($this->student);
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.$i"])
                ->postJson('/api/student/assessments/999999/explanations/generate');

            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                "Request $i came from a never-before-seen IP and must not be throttled yet."
            );
            $this->assertSame(404, $response->getStatusCode());
        }

        // The 11th request uses yet another fresh IP and is STILL throttled:
        // proof the bucket is keyed by student id, not by IP.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->postJson('/api/student/assessments/999999/explanations/generate')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    /**
     * Rejected (throttled) requests must be PURE rejections: zero upstream
     * HTTP calls and zero rows written. The student owns a released submission
     * WITH incorrect answers so any accidental generation attempt would be
     * observable as wire traffic plus persisted rows.
     */
    /** @test */
    public function test_generate_beyond_budget_is_a_pure_rejection_with_zero_side_effects()
    {
        $this->setUpOrg();
        config()->set('services.openrouter.mock', false);
        config()->set('services.openrouter.api_key', 'fake-key-for-testing');
        Http::fake();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X']);

        // Burn the whole per-student budget on requests that cannot generate
        // (unknown assessment id → 404); each still consumes one token.
        Sanctum::actingAs($this->student);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/student/assessments/999999/explanations/generate')
                ->assertStatus(404);
        }

        // Budget exhausted → the request against the REAL released assessment
        // is rejected without calling OpenRouter or writing anything.
        $this->postJson("/api/student/assessments/{$assessment->id}/explanations/generate")
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_explanations', 0);
    }

    /**
     * RT-7 corrected outage contract: with two competency groups, an upstream
     * failure on the SECOND call must (a) keep the FIRST group's rows, which
     * were stored immediately upon receipt — deliberately NO wrapping
     * transaction across upstream I/O (ARCH-002 FR-027), (b) surface the 503
     * AI_SERVICE_UNAVAILABLE envelope, and (c) on retry regenerate ONLY the
     * still-missing group — exactly one extra upstream call — leaving every
     * incorrect item covered exactly once (append-only; survivors are reused,
     * never deleted-and-regenerated).
     *
     * S-7: a single transient failure is retried once and recovers, so the
     * outage on the second group must be PERSISTENT (both the initial call
     * and the bounded retry fail) to surface the 503 envelope here.
     */
    /** @test */
    public function test_generate_partial_outage_persists_completed_group_and_retry_generates_only_missing()
    {
        $this->setUpOrg();
        config()->set('services.openrouter.mock', false);
        config()->set('services.openrouter.api_key', 'fake-key-for-testing');

        $upstreamCalls = 0;
        Http::fake(function () use (&$upstreamCalls) {
            $upstreamCalls++;

            // S-7 persistent outage: the second group's initial call AND its
            // single bounded retry both fail, so the 503 envelope surfaces.
            if ($upstreamCalls === 2 || $upstreamCalls === 3) {
                return Http::response([], 500);
            }

            return Http::response([
                'choices' => [
                    ['message' => ['content' => 'Explanation ' . $upstreamCalls . '.']]]], 200);
        });

        // Group 1 = Q1 (competency tag 1); group 2 = Q2 + Q3 (competency tag 2).
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
                'competency_tag_id' => $this->competencyTag2->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q3',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag2->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X', 'Y', 'Z']);

        $wrongItemIds = AssessmentItem::query()
            ->where('assessment_id', $assessment->id)
            ->whereIn('prompt', ['Q1', 'Q2', 'Q3'])
            ->pluck('id')
            ->sort()
            ->values();
        $this->assertCount(3, $wrongItemIds);

        $first = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        // Persistent-outage envelope on the overall request (initial + retry
        // both failed)...
        $first->assertStatus(503);
        $first->assertJsonPath('error.code', 'AI_SERVICE_UNAVAILABLE');
        $this->assertSame(3, $upstreamCalls);

        // ...but rows for exactly ONE complete competency group persisted.
        $persistedIds = AIExplanation::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $assessment->id)
            ->where('is_follow_up', false)
            ->pluck('item_id')
            ->sort()
            ->values();
        $groupOneIds = AssessmentItem::query()
            ->where('assessment_id', $assessment->id)
            ->where('competency_tag_id', $this->competencyTag1->id)
            ->whereIn('prompt', ['Q1'])
            ->pluck('id')
            ->sort()
            ->values();
        $groupTwoIds = AssessmentItem::query()
            ->where('assessment_id', $assessment->id)
            ->where('competency_tag_id', $this->competencyTag2->id)
            ->whereIn('prompt', ['Q2', 'Q3'])
            ->pluck('id')
            ->sort()
            ->values();
        $this->assertNotEmpty($persistedIds);
        $this->assertTrue(
            $persistedIds->all() === $groupOneIds->all()
                || $persistedIds->all() === $groupTwoIds->all(),
            'After a mid-pipeline outage, persisted rows must be exactly one complete competency group.'
        );

        $survivorRowIds = AIExplanation::query()
            ->where('student_id', $this->student->id)
            ->where('assessment_id', $assessment->id)
            ->pluck('id')
            ->all();

        // Retry: ONLY the still-missing group regenerates → exactly one more
        // upstream call; final payload covers every incorrect item once.
        $retry = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        $retry->assertOk();
        $this->assertSame(4, $upstreamCalls);

        $data = collect($retry->json('data'));
        $this->assertEquals($wrongItemIds->all(), $data->pluck('item_id')->sort()->values()->all());
        $this->assertDatabaseCount('ai_explanations', 3);

        // Pre-outage survivor rows were reused, not regenerated (append-only).
        $this->assertSame(
            count($survivorRowIds),
            AIExplanation::query()->whereIn('id', $survivorRowIds)->count()
        );
    }

    /**
     * S-7 bounded retry recovery: with two competency groups, a SINGLE
     * transient upstream failure (HTTP 500) on the second group call is
     * retried once and recovers — the overall request returns 200 with every
     * incorrect item covered exactly once, at the cost of exactly one extra
     * upstream call. Fail-closed is preserved: only a persistent failure
     * (both attempts fail, see the partial-outage test above) surfaces 503.
     */
    /** @test */
    public function test_generate_single_transient_failure_recovers_with_retry()
    {
        $this->setUpOrg();
        config()->set('services.openrouter.mock', false);
        config()->set('services.openrouter.api_key', 'fake-key-for-testing');

        $upstreamCalls = 0;
        Http::fake(function () use (&$upstreamCalls) {
            $upstreamCalls++;

            // Only the second upstream call fails (transient); the S-7 retry
            // of that same group call succeeds.
            if ($upstreamCalls === 2) {
                return Http::response([], 500);
            }

            return Http::response([
                'choices' => [
                    ['message' => ['content' => 'Explanation ' . $upstreamCalls . '.']]]], 200);
        });

        // Group 1 = Q1 (competency tag 1); group 2 = Q2 + Q3 (competency tag 2).
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
                'competency_tag_id' => $this->competencyTag2->id],
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q3',
                'max_points' => 10,
                'correct_answer' => 'C',
                'competency_tag_id' => $this->competencyTag2->id]]);

        $this->startAndSubmitAndRelease($this->student, $assessment->id, ['X', 'Y', 'Z']);

        $wrongItemIds = AssessmentItem::query()
            ->where('assessment_id', $assessment->id)
            ->whereIn('prompt', ['Q1', 'Q2', 'Q3'])
            ->pluck('id')
            ->sort()
            ->values();
        $this->assertCount(3, $wrongItemIds);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        // Recovered via the single retry: 200 with all groups persisted —
        // first group (1 call) + second group (initial + 1 retry).
        $response->assertOk();
        $this->assertSame(3, $upstreamCalls);

        $data = collect($response->json('data'));
        $this->assertEquals($wrongItemIds->all(), $data->pluck('item_id')->sort()->values()->all());
        $this->assertDatabaseCount('ai_explanations', 3);
    }

    /**
     * IDOR permanent pin: a student who POSTs generate for ANOTHER student's
     * released assessment gets 404 NOT_FOUND — nobody gets rows and the
     * upstream wire is never touched.
     */
    /** @test */
    public function test_generate_for_another_students_released_assessment_returns_404_with_zero_side_effects()
    {
        $this->setUpOrg();
        config()->set('services.openrouter.mock', false);
        config()->set('services.openrouter.api_key', 'fake-key-for-testing');
        Http::fake();

        $assessment = $this->createReleasedAssessment('Recorded', [
            [
                'item_type' => 'multiple_choice',
                'prompt' => 'Q1',
                'max_points' => 10,
                'correct_answer' => 'A',
                'competency_tag_id' => $this->competencyTag1->id]]);

        // Student B owns the only released submission; student A (enrolled in
        // the same section, but with no submission here) attacks B's id.
        $this->startAndSubmitAndRelease($this->studentB, $assessment->id, ['X']);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/student/assessments/{$assessment->id}/explanations/generate");

        $response->assertStatus(404);
        $response->assertJsonStructure(['error' => ['message', 'code']]);
        $this->assertSame('NOT_FOUND', $response->json('error.code'));

        $this->assertDatabaseCount('ai_explanations', 0);
        Http::assertNothingSent();
    }
}
